<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private CartService $cart;

    public function __construct(?CartService $cart = null)
    {
        $this->cart = $cart ?? new CartService;
    }

    /**
     * Fecha o pedido numa ÚNICA transação: cliente, estoque, número do pedido, pedido e
     * itens. Se qualquer passo falhar (inclusive estoque insuficiente), nada é gravado.
     *
     * @throws InsufficientStockException produto com `track_inventory` sem saldo
     */
    public function createFromCart(
        Tenant $tenant,
        array $customerData,
        array $cart,
        string $paymentMethod,
        array $paymentMeta = []
    ): Order {
        return DB::transaction(function () use ($tenant, $customerData, $cart, $paymentMethod, $paymentMeta) {
            $customer = $this->resolveCustomer($tenant, $customerData);
            $lines = $this->resolveCartLines($tenant, $cart);
            $subtotalCents = $this->calculateSubtotal($lines);

            // ANTES de Order::create: se faltar estoque falha cedo e o contador de pedidos
            // nem é tocado (e, se fosse, voltaria junto com o rollback).
            $trackedProductIds = $this->reserveStock($tenant, $lines);

            $order = Order::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'status' => OrderStatus::PENDING,
                'payment_status' => PaymentStatus::UNPAID,
                'payment_method' => $paymentMethod,
                'customer_name' => $customerData['name'] ?? $customer->name,
                'customer_phone' => $customerData['phone'] ?? $customer->phone,
                'fulfillment_type' => 'pickup',
                'subtotal_cents' => $subtotalCents,
                'discount_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_cents' => $subtotalCents,
                'meta' => [
                    'payment' => $paymentMeta,
                ],
            ]);

            $this->createOrderItems($tenant, $order, $lines, $trackedProductIds);

            return $order;
        });
    }

    /**
     * Resolve o cliente do pedido pelo telefone (formato canônico, {@see Phone}).
     *
     * DECISÃO DE PRODUTO (E3.2b, variante conservadora): o checkout é público, então
     * quem conhecer o telefone de um cliente NÃO pode reescrever os dados dele. Ao
     * encontrar um cliente existente, só PREENCHEMOS campos vazios (nome/e-mail);
     * nome, e-mail e telefone já preenchidos nunca são sobrescritos aqui (o cliente
     * edita o próprio perfil em `Account/Profile`, e o admin em `Admin/Customers`).
     * O pedido guarda o snapshot informado (`customer_name`/`customer_phone`).
     *
     * Telefone vazio ou inválido não identifica ninguém: cria um cliente sem telefone
     * (nunca agrupa pedidos de pessoas diferentes sob um número lixo).
     */
    public function resolveCustomer(Tenant $tenant, array $customerData): Customer
    {
        $name = trim((string) ($customerData['name'] ?? ''));
        $email = trim((string) ($customerData['email'] ?? ''));
        $rawPhone = trim((string) ($customerData['phone'] ?? ''));
        $phone = Phone::isValid($rawPhone) ? Phone::normalize($rawPhone) : null;

        $attributes = [
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'meta' => [],
        ];

        if ($phone === null) {
            return Customer::create(array_merge($attributes, ['tenant_id' => $tenant->id, 'phone' => null]));
        }

        $customer = Customer::firstOrCreateByPhone($tenant->id, $phone, $attributes);

        if ($customer->wasRecentlyCreated) {
            return $customer;
        }

        // Cliente existente: preenche apenas o que está vazio.
        if ($name !== '' && blank($customer->name)) {
            $customer->name = $name;
        }
        if ($email !== '' && blank($customer->email)) {
            $customer->email = $email;
        }
        if ($customer->isDirty()) {
            $customer->save();
        }

        return $customer;
    }

    /**
     * Linhas do carrinho — delega ao {@see CartService} (única fonte de verdade).
     *
     * @param  array<mixed>  $cart
     * @return list<array{product_id: string, product: Product, qty: int, unit_cents: int, total_cents: int, available: int|null}>
     */
    public function resolveCartLines(Tenant $tenant, array $cart): array
    {
        return $this->cart->resolveLines($tenant, $cart);
    }

    /**
     * @param  list<array{total_cents: int}>  $lines
     */
    public function calculateSubtotal(array $lines): int
    {
        return $this->cart->subtotalCents($lines);
    }

    /**
     * Valida e baixa o estoque dos produtos com `track_inventory = true`.
     * Deve rodar DENTRO da transação do pedido.
     *
     * - quantidades de linhas repetidas do mesmo produto são SOMADAS antes de validar;
     * - os produtos são travados num único `SELECT ... FOR UPDATE ORDER BY id`: a ordem
     *   estável impede deadlock entre pedidos concorrentes com produtos em comum;
     * - o saldo é lido da linha TRAVADA (valor commitado mais recente), não do model
     *   carregado antes — é isso que impede a venda dupla do último item;
     * - produto com `track_inventory = false` não é validado nem travado.
     *
     * @param  list<array{product: Product, qty: int}>  $lines
     * @return array<string, true> ids dos produtos que tiveram baixa
     *
     * @throws InsufficientStockException
     */
    protected function reserveStock(Tenant $tenant, array $lines): array
    {
        $needed = [];
        foreach ($lines as $line) {
            if ($line['product']->track_inventory) {
                $id = $line['product']->id;
                $needed[$id] = ($needed[$id] ?? 0) + $line['qty'];
            }
        }

        if ($needed === []) {
            return [];
        }

        $locked = Product::query()
            ->forTenant($tenant->id)
            ->whereIn('id', array_keys($needed))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $reserved = [];

        foreach ($needed as $productId => $qty) {
            /** @var Product|null $product */
            $product = $locked->get($productId);

            if ($product === null) {
                // Excluído entre a leitura do carrinho e o lock: não há o que vender.
                $name = collect($lines)->first(fn ($l) => $l['product']->id === $productId)['product']->name;

                throw new InsufficientStockException($productId, $name, $qty, 0);
            }

            if (! $product->track_inventory) {
                continue; // controle desligado no meio do caminho: vira "sob encomenda"
            }

            $available = (int) $product->stock_quantity;

            if ($available < $qty) {
                throw new InsufficientStockException($productId, $product->name, $qty, max(0, $available));
            }

            $reserved[$productId] = true;
        }

        foreach ($reserved as $productId => $_) {
            Product::query()
                ->forTenant($tenant->id)
                ->whereKey($productId)
                ->update(['stock_quantity' => (int) $locked->get($productId)->stock_quantity - $needed[$productId]]);
        }

        return $reserved;
    }

    /**
     * Atualiza status/pagamento do pedido e mantém o estoque coerente, tudo numa transação.
     *
     * - Entrar em `cancelled`: devolve ao estoque o que o pedido baixou (uma única vez).
     * - Sair de `cancelled` (reativar): baixa de novo; sem saldo, lança e NADA muda.
     * - Salvar `cancelled` de novo não faz nada no estoque (idempotente).
     *
     * A linha do pedido é travada e relida: duas telas de admin abertas com o mesmo
     * pedido não conseguem devolver o estoque duas vezes (a 2ª já enxerga `cancelled`).
     *
     * @throws InsufficientStockException ao reativar sem saldo
     */
    public function updateOrder(Order $order, OrderStatus $status, PaymentStatus $paymentStatus, ?string $paymentMethod): Order
    {
        return DB::transaction(function () use ($order, $status, $paymentStatus, $paymentMethod) {
            $current = Order::query()
                ->forTenant($order->tenant_id)
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasCancelled = $current->status === OrderStatus::CANCELLED;
            $willBeCancelled = $status === OrderStatus::CANCELLED;

            if (! $wasCancelled && $willBeCancelled) {
                $this->releaseStock($current);
            } elseif ($wasCancelled && ! $willBeCancelled) {
                $this->reacquireStock($current);
            }

            $current->status = $status;
            $current->payment_status = $paymentStatus;
            $current->payment_method = $paymentMethod;
            $current->save();

            return $order->refresh();
        });
    }

    /**
     * Itens do pedido que baixaram estoque, agrupados por produto (ordenados por id).
     *
     * @return Collection<int|string, Collection<int, OrderItem>>
     */
    private function stockItemsByProduct(Order $order, bool $released): Collection
    {
        return OrderItem::query()
            ->forTenant($order->tenant_id)
            ->where('order_id', $order->id)
            ->whereNotNull('product_id')
            ->where('stock_deducted_quantity', '>', 0)
            ->when($released, fn ($q) => $q->whereNotNull('stock_released_at'), fn ($q) => $q->whereNull('stock_released_at'))
            ->get()
            ->toBase()
            ->groupBy('product_id')
            ->sortKeys();
    }

    private function releaseStock(Order $order): void
    {
        foreach ($this->stockItemsByProduct($order, released: false) as $productId => $items) {
            // Só devolve se o produto ainda controla estoque (e não, se foi desligado/excluído).
            Product::query()
                ->forTenant($order->tenant_id)
                ->whereKey($productId)
                ->where('track_inventory', true)
                ->update(['stock_quantity' => DB::raw('stock_quantity + '.(int) $items->sum('stock_deducted_quantity'))]);

            OrderItem::query()
                ->forTenant($order->tenant_id)
                ->whereIn('id', $items->pluck('id'))
                ->update(['stock_released_at' => now()]);
        }
    }

    private function reacquireStock(Order $order): void
    {
        $groups = $this->stockItemsByProduct($order, released: true);

        if ($groups->isEmpty()) {
            return;
        }

        $locked = Product::query()
            ->forTenant($order->tenant_id)
            ->whereIn('id', $groups->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($groups as $productId => $items) {
            /** @var Product|null $product */
            $product = $locked->get($productId);

            if ($product === null || ! $product->track_inventory) {
                continue; // sem controle hoje: nada a rebaixar (a marca de devolução fica)
            }

            $qty = (int) $items->sum('stock_deducted_quantity');

            if ((int) $product->stock_quantity < $qty) {
                throw new InsufficientStockException($productId, $product->name, $qty, max(0, (int) $product->stock_quantity));
            }

            Product::query()
                ->forTenant($order->tenant_id)
                ->whereKey($productId)
                ->update(['stock_quantity' => (int) $product->stock_quantity - $qty]);

            OrderItem::query()
                ->forTenant($order->tenant_id)
                ->whereIn('id', $items->pluck('id'))
                ->update(['stock_released_at' => null]);
        }
    }

    /**
     * @param  array<string, true>  $trackedProductIds
     */
    protected function createOrderItems(Tenant $tenant, Order $order, array $lines, array $trackedProductIds = []): void
    {
        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];

            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $line['qty'],
                'stock_deducted_quantity' => isset($trackedProductIds[$product->id]) ? $line['qty'] : 0,
                'unit_price_cents' => $line['unit_cents'],
                'total_cents' => $line['total_cents'],
                'notes' => null,
                'meta' => [],
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\CartStockLimitException;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * ÚNICA fonte de verdade do carrinho (ARC-008).
 *
 * - Armazenamento: sessão, chave `cart.{tenant_id}`, lista de `{product_id, qty}`.
 *   Nada mais é guardado: preço, nome e estoque são SEMPRE lidos do banco.
 * - Linha resolvida ({@see resolveLines()}): só entram produtos do tenant, ativos e
 *   não excluídos. Produto inexistente, inativo, excluído ou de outro tenant é
 *   descartado (nunca precificado).
 * - Totais em centavos, calculados aqui; o total NUNCA vem do frontend e o
 *   `OrderService::createFromCart` o recalcula no servidor com esta mesma lógica.
 * - Estoque: com `track_inventory`, `add`/`increment` não passam do saldo
 *   ({@see CartStockLimitException}). Sem controle, não há limite.
 *
 * Forma de uma linha resolvida:
 * `array{product_id: string, product: Product, qty: int, unit_cents: int, total_cents: int, available: int|null}`
 * (`available` null = sem controle de estoque / sob encomenda).
 *
 * Não confie em estado de componente Livewire (`public` é editável pelo cliente): toda
 * operação aqui parte da sessão e do banco.
 */
class CartService
{
    public static function sessionKey(Tenant|string $tenant): string
    {
        return 'cart.'.($tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    // ------------------------------------------------------------------ leitura

    /**
     * Itens da sessão, saneados: `product_id` UUID e `qty` inteiro >= 1.
     *
     * @return list<array{product_id: string, qty: int}>
     */
    public function items(Tenant $tenant): array
    {
        return $this->sanitize(session()->get(self::sessionKey($tenant), []));
    }

    /**
     * Linhas do carrinho da sessão.
     *
     * @return list<array{product_id: string, product: Product, qty: int, unit_cents: int, total_cents: int, available: int|null}>
     */
    public function lines(Tenant $tenant): array
    {
        return $this->resolveLines($tenant, $this->items($tenant));
    }

    /**
     * Resolve itens arbitrários (ex.: o carrinho recebido por `OrderService::createFromCart`).
     * Uma única consulta; a ordem dos itens é preservada.
     *
     * @param  array<mixed>  $items
     * @return list<array{product_id: string, product: Product, qty: int, unit_cents: int, total_cents: int, available: int|null}>
     */
    public function resolveLines(Tenant $tenant, array $items): array
    {
        $items = $this->sanitize($items);

        if ($items === []) {
            return [];
        }

        $products = Product::query()
            ->forTenant($tenant->id)
            ->active()
            ->whereIn('id', array_values(array_unique(array_column($items, 'product_id'))))
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product instanceof Product) {
                continue;
            }

            $unitCents = (int) $product->price_cents;

            $lines[] = [
                'product_id' => $product->id,
                'product' => $product,
                'qty' => $item['qty'],
                'unit_cents' => $unitCents,
                'total_cents' => $unitCents * $item['qty'],
                'available' => $product->availableQuantity(),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{total_cents: int}>  $lines
     */
    public function subtotalCents(array $lines): int
    {
        return (int) array_sum(array_column($lines, 'total_cents'));
    }

    /**
     * Total a pagar em centavos. Hoje igual ao subtotal (sem frete/desconto no carrinho);
     * existe para que ninguém some linhas por conta própria.
     *
     * @param  list<array{total_cents: int}>  $lines
     */
    public function totalCents(array $lines): int
    {
        return $this->subtotalCents($lines);
    }

    /**
     * Quantidade de linhas VÁLIDAS (badge do header). Produto inativo/excluído não conta.
     */
    public function count(Tenant $tenant): int
    {
        return count($this->lines($tenant));
    }

    // ------------------------------------------------------------------ escrita

    /**
     * Adiciona `$qty` do produto, somando à linha existente.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException produto inexistente, inativo ou de outro tenant
     * @throws CartStockLimitException
     */
    public function add(Tenant $tenant, string $productId, int $qty = 1): void
    {
        $qty = max(1, $qty);

        $product = $this->activeProduct($tenant, $productId);
        $items = $this->items($tenant);

        $available = $product->availableQuantity();

        if ($available !== null) {
            $inCart = $this->quantityInCart($items, $product->id);

            if ($available <= 0) {
                throw new CartStockLimitException('Produto esgotado.', $product->id, 0);
            }

            if ($inCart + $qty > $available) {
                $remaining = max(0, $available - $inCart);

                throw new CartStockLimitException(
                    $remaining === 0
                        ? "Você já tem todas as {$available} unidade(s) disponíveis no carrinho."
                        : "Só é possível adicionar mais {$remaining} unidade(s) deste produto.",
                    $product->id,
                    $available,
                );
            }
        }

        $found = false;
        foreach ($items as &$item) {
            if ($item['product_id'] === $product->id) {
                $item['qty'] += $qty;
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $items[] = ['product_id' => $product->id, 'qty' => $qty];
        }

        $this->store($tenant, $items);
    }

    /**
     * +1 numa linha existente, limitado ao estoque (lido do banco, não do estado do componente).
     * Produto que não está mais disponível (inativo/excluído) ou fora do carrinho: não faz nada.
     *
     * @throws CartStockLimitException
     */
    public function increment(Tenant $tenant, string $productId): void
    {
        $items = $this->items($tenant);

        if ($this->quantityInCart($items, $productId) === 0) {
            return;
        }

        $product = Product::query()->forTenant($tenant->id)->active()->whereKey($productId)->first();

        if (! $product instanceof Product) {
            return;
        }

        $available = $product->availableQuantity();

        foreach ($items as &$item) {
            if ($item['product_id'] === $productId) {
                $next = $item['qty'] + 1;

                if ($available !== null && $next > $available) {
                    throw new CartStockLimitException(
                        "Só temos {$available} unidade(s) de \"{$product->name}\" disponíveis.",
                        $productId,
                        $available,
                    );
                }

                $item['qty'] = $next;
                break;
            }
        }
        unset($item);

        $this->store($tenant, $items);
    }

    /**
     * -1 numa linha existente, no mínimo 1 (para remover use {@see remove()}).
     */
    public function decrement(Tenant $tenant, string $productId): void
    {
        $items = $this->items($tenant);

        foreach ($items as &$item) {
            if ($item['product_id'] === $productId) {
                $item['qty'] = max(1, $item['qty'] - 1);
                break;
            }
        }
        unset($item);

        $this->store($tenant, $items);
    }

    public function remove(Tenant $tenant, string $productId): void
    {
        $this->store($tenant, array_values(array_filter(
            $this->items($tenant),
            fn (array $item) => $item['product_id'] !== $productId
        )));
    }

    public function clear(Tenant $tenant): void
    {
        session()->forget(self::sessionKey($tenant));
    }

    // ------------------------------------------------------------------ internos

    /**
     * @param  list<array{product_id: string, qty: int}>  $items
     */
    private function store(Tenant $tenant, array $items): void
    {
        session()->put(self::sessionKey($tenant), $items);
    }

    /**
     * @param  list<array{product_id: string, qty: int}>  $items
     */
    private function quantityInCart(array $items, string $productId): int
    {
        return (int) array_sum(array_map(
            fn (array $item) => $item['product_id'] === $productId ? $item['qty'] : 0,
            $items
        ));
    }

    private function activeProduct(Tenant $tenant, string $productId): Product
    {
        return Product::query()
            ->forTenant($tenant->id)
            ->active()
            ->whereKey($productId)
            ->firstOrFail();
    }

    /**
     * Descarta o que não é item válido: sem `product_id` UUID (a coluna é uuid no
     * PostgreSQL; outro valor estouraria na consulta) ou com `qty` < 1.
     *
     * @return list<array{product_id: string, qty: int}>
     */
    private function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }

            $productId = strtolower((string) ($line['product_id'] ?? ''));
            $qty = (int) ($line['qty'] ?? 0);

            if (! Str::isUuid($productId) || $qty < 1) {
                continue;
            }

            $items[] = ['product_id' => $productId, 'qty' => $qty];
        }

        return $items;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conteúdo de demonstração do tenant "Carol Doces".
 *
 * Idempotente: todas as entidades são resolvidas por chave natural
 * (slug, telefone ou meta.demo_key), então rodar o seeder várias vezes
 * atualiza os registros existentes em vez de duplicá-los.
 *
 * ATENÇÃO: a idempotência é obtida com `updateOrCreate`, ou seja, o re-seed
 * SOBRESCREVE alterações manuais feitas nos registros de demonstração
 * (categorias, produtos, clientes e vitrines voltam aos valores definidos
 * aqui). Pedidos e itens de pedido são a exceção: já existindo o
 * `meta.demo_key`, o pedido é ignorado e permanece intacto.
 *
 * Os eventos de model precisam estar ativos durante o seeding: Order::booted()
 * gera o `number` sequencial e Product/Category/Showcase::booted() geram os
 * slugs. Por isso o DatabaseSeeder NÃO usa a trait WithoutModelEvents.
 */
class CarolDocesDemoSeeder extends Seeder
{
    private const TENANT_SLUG = 'caroldoces';

    public function run(): void
    {
        $tenant = Tenant::where('slug', self::TENANT_SLUG)->firstOrFail();

        $categories = $this->seedCategories($tenant->id);
        $products = $this->seedProducts($tenant->id, $categories);
        $customers = $this->seedCustomers($tenant->id);

        $this->seedOrders($tenant->id, $products, $customers);
        $this->seedShowcases($tenant->id, $categories, $products);
    }

    /** @return array<string, Category> indexado por slug */
    private function seedCategories(string $tenantId): array
    {
        $rows = [
            ['slug' => 'bolos', 'name' => 'Bolos', 'description' => 'Bolos caseiros e decorados para todas as ocasiões.', 'sort_order' => 1],
            ['slug' => 'doces-finos', 'name' => 'Doces Finos', 'description' => 'Brigadeiros gourmet, bem-casados e trufas artesanais.', 'sort_order' => 2],
            ['slug' => 'tortas', 'name' => 'Tortas', 'description' => 'Tortas geladas, cheesecakes e mousses.', 'sort_order' => 3],
            ['slug' => 'salgados', 'name' => 'Salgados', 'description' => 'Salgados fritos e assados para festas.', 'sort_order' => 4],
            ['slug' => 'kits-festa', 'name' => 'Kits Festa', 'description' => 'Combos prontos para aniversários e comemorações.', 'sort_order' => 5],
        ];

        $categories = [];

        foreach ($rows as $row) {
            $categories[$row['slug']] = Category::updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<string, Product> indexado por sku
     */
    private function seedProducts(string $tenantId, array $categories): array
    {
        // [sku, nome, preço (R$), preço riscado, categorias, destaque, ativo, dias de antecedência]
        $rows = [
            ['BOL-001', 'Bolo de Chocolate com Morango 1kg', 89.90, 109.90, ['bolos'], true, true, 3, 'Massa de chocolate belga, recheio de brigadeiro e morangos frescos.'],
            ['BOL-002', 'Bolo Red Velvet 1,5kg', 129.90, null, ['bolos'], false, true, 3, 'Massa red velvet com cream cheese e cobertura aveludada.'],
            ['BOL-003', 'Bolo de Cenoura com Brigadeiro', 69.90, null, ['bolos'], false, true, 2, 'O clássico de sempre, com cobertura generosa de brigadeiro.'],
            ['BOL-004', 'Naked Cake de Frutas Vermelhas', 159.90, 179.90, ['bolos', 'kits-festa'], true, true, 5, 'Bolo rústico de três andares com frutas vermelhas da estação.'],
            ['BOL-005', 'Bolo Vulcão de Ninho com Nutella', 74.90, null, ['bolos'], false, true, 2, 'Recheio cremoso de leite ninho que escorre na hora de cortar.'],

            ['DOC-001', 'Brigadeiro Gourmet (cento)', 119.90, 139.90, ['doces-finos'], false, true, 2, 'Cento de brigadeiros gourmet em sabores variados.'],
            ['DOC-002', 'Beijinho Tradicional (cento)', 109.90, null, ['doces-finos'], false, true, 2, 'Beijinhos de coco com cravo, feitos no ponto certo.'],
            ['DOC-003', 'Bem-casado (dúzia)', 48.00, null, ['doces-finos', 'kits-festa'], false, true, 3, 'Bem-casados com doce de leite argentino e embalagem personalizada.'],
            ['DOC-004', 'Trufa de Maracujá', 6.50, null, ['doces-finos'], false, true, null, 'Trufa de chocolate meio amargo com ganache de maracujá.'],
            ['DOC-005', 'Copinho de Doce de Leite', 7.90, 9.90, ['doces-finos'], false, true, null, 'Copinho individual de doce de leite com farofa de castanha.'],
            ['DOC-006', 'Camafeu de Nozes (dúzia)', 62.00, null, ['doces-finos'], false, false, null, 'Produto sazonal — fora de linha no momento.'],

            ['TOR-001', 'Torta Holandesa (fatia)', 18.90, null, ['tortas'], true, true, null, 'Fatia generosa com base de biscoito e cobertura de chocolate.'],
            ['TOR-002', 'Torta de Limão Inteira', 94.90, null, ['tortas'], false, true, 2, 'Creme de limão siciliano com merengue maçaricado.'],
            ['TOR-003', 'Cheesecake de Frutas Vermelhas', 109.90, 129.90, ['tortas'], false, true, 2, 'Cheesecake cremoso com calda artesanal de frutas vermelhas.'],
            ['TOR-004', 'Torta Mousse de Maracujá', 89.90, null, ['tortas'], false, true, 2, 'Mousse aerado de maracujá sobre base crocante.'],

            ['SAL-001', 'Coxinha de Frango com Catupiry (cento)', 149.90, null, ['salgados'], false, true, 2, 'Cento de coxinhas fritas na hora ou congeladas.'],
            ['SAL-002', 'Mini Quiche de Alho-poró (dúzia)', 54.90, null, ['salgados'], false, true, null, 'Mini quiches assadas, ideais para brunch.'],
            ['SAL-003', 'Empadinha de Palmito (cento)', 159.90, null, ['salgados'], false, false, 2, 'Temporariamente indisponível por falta de insumo.'],

            ['KIT-001', 'Kit Festa Infantil (30 pessoas)', 599.90, 699.90, ['kits-festa'], true, true, 5, 'Bolo de 2kg, 200 docinhos e 200 salgadinhos.'],
            ['KIT-002', 'Kit Chá de Bebê (20 pessoas)', 429.90, null, ['kits-festa'], false, true, 4, 'Bolo de 1,5kg, 100 docinhos, 100 salgadinhos e bem-casados.'],
        ];

        $products = [];

        foreach ($rows as $i => [$sku, $name, $price, $compareAt, $categorySlugs, $isFeatured, $isActive, $minDays, $description]) {
            $product = Product::updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'sku' => $sku,
                    'description' => $description,
                    'price' => $price,
                    'compare_at' => $compareAt,
                    'is_active' => $isActive,
                    'is_featured' => $isFeatured,
                    'sort_order' => ($i + 1) * 10,
                    'min_days_notice' => $minDays,
                    'requires_scheduling' => $minDays !== null,
                ]
            );

            $product->syncCategories(
                array_map(fn (string $slug) => $categories[$slug]->id, $categorySlugs)
            );

            $products[$sku] = $product;
        }

        return $products;
    }

    /** @return array<string, Customer> indexado por telefone */
    private function seedCustomers(string $tenantId): array
    {
        // [telefone, nome, e-mail, dias desde o último login] — valores fixos
        // para manter o seed reproduzível entre execuções.
        $rows = [
            ['11987654321', 'Ana Paula Ribeiro', 'ana.ribeiro@example.com', 1],
            ['11991234567', 'Bruno Carvalho', 'bruno.carvalho@example.com', 3],
            ['11993456789', 'Camila Souza', 'camila.souza@example.com', 5],
            ['11994567890', 'Diego Martins', 'diego.martins@example.com', 8],
            ['11995678901', 'Elaine Ferreira', 'elaine.ferreira@example.com', 13],
            ['11996789012', 'Fábio Nogueira', 'fabio.nogueira@example.com', 20],
        ];

        $customers = [];

        foreach ($rows as [$phone, $name, $email, $lastLoginDaysAgo]) {
            $customers[$phone] = Customer::updateOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $phone],
                [
                    'name' => $name,
                    'email' => $email,
                    'is_active' => true,
                    'last_login_at' => now()->subDays($lastLoginDaysAgo)->startOfDay(),
                ]
            );
        }

        return $customers;
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<string, Customer>  $customers
     */
    private function seedOrders(string $tenantId, array $products, array $customers): void
    {
        $address = [
            'address_line1' => 'Rua das Acácias, 245',
            'address_line2' => 'Apto 52, Bloco B',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'address_postal_code' => '05432010',
        ];

        $rows = [
            [
                'key' => 'demo-01', 'phone' => '11987654321', 'days_ago' => 28,
                'status' => 'done', 'payment_status' => 'paid', 'payment_method' => PaymentMethod::PIX_MANUAL->value,
                'fulfillment_type' => 'pickup', 'scheduled_in' => -25,
                'notes' => 'Retirada às 15h. Escrever "Parabéns, Lucas!" no bolo.',
                'discount_cents' => 0, 'delivery_fee_cents' => 0,
                'items' => [['BOL-001', 1, 'Sem açúcar no recheio'], ['DOC-004', 10, null]],
            ],
            [
                'key' => 'demo-02', 'phone' => '11991234567', 'days_ago' => 24,
                'status' => 'done', 'payment_status' => 'paid', 'payment_method' => PaymentMethod::MP_CREDIT_CARD->value,
                'fulfillment_type' => 'delivery', 'scheduled_in' => -21,
                'notes' => 'Entregar na portaria.',
                'discount_cents' => 1000, 'delivery_fee_cents' => 1500,
                'items' => [['KIT-001', 1, null], ['DOC-003', 2, 'Fita azul']],
            ],
            [
                'key' => 'demo-03', 'phone' => '11993456789', 'days_ago' => 19,
                'status' => 'cancelled', 'payment_status' => 'refunded', 'payment_method' => PaymentMethod::MP_PIX->value,
                'fulfillment_type' => 'delivery', 'scheduled_in' => -16,
                'notes' => 'Cliente cancelou — festa adiada. Estorno feito via Mercado Pago.',
                'discount_cents' => 0, 'delivery_fee_cents' => 2000,
                'items' => [['TOR-003', 1, null]],
            ],
            [
                'key' => 'demo-04', 'phone' => '11994567890', 'days_ago' => 15,
                'status' => 'done', 'payment_status' => 'paid', 'payment_method' => PaymentMethod::CASH_ON_DELIVERY->value,
                'fulfillment_type' => 'pickup', 'scheduled_in' => -13,
                'notes' => null,
                'discount_cents' => 0, 'delivery_fee_cents' => 0,
                'items' => [['TOR-001', 6, null], ['DOC-005', 4, null]],
            ],
            [
                'key' => 'demo-05', 'phone' => '11995678901', 'days_ago' => 11,
                'status' => 'cancelled', 'payment_status' => 'unpaid', 'payment_method' => PaymentMethod::PIX_MANUAL->value,
                'fulfillment_type' => 'pickup', 'scheduled_in' => -8,
                'notes' => 'Pagamento não confirmado dentro do prazo.',
                'discount_cents' => 0, 'delivery_fee_cents' => 0,
                'items' => [['SAL-001', 1, null]],
            ],
            [
                'key' => 'demo-06', 'phone' => '11996789012', 'days_ago' => 8,
                'status' => 'confirmed', 'payment_status' => 'paid', 'payment_method' => PaymentMethod::MP_PIX->value,
                'fulfillment_type' => 'delivery', 'scheduled_in' => 3,
                'notes' => 'Chá de bebê — tema safári. Entregar até as 10h.',
                'discount_cents' => 2500, 'delivery_fee_cents' => 1800,
                'items' => [['KIT-002', 1, null], ['SAL-002', 3, 'Sem cebola']],
            ],
            [
                'key' => 'demo-07', 'phone' => '11987654321', 'days_ago' => 6,
                'status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => PaymentMethod::CARD_ON_DELIVERY->value,
                'fulfillment_type' => 'delivery', 'scheduled_in' => 5,
                'notes' => 'Cobrar na entrega. Maquininha necessária.',
                'discount_cents' => 0, 'delivery_fee_cents' => 1500,
                'items' => [['BOL-004', 1, 'Frutas extras'], ['DOC-001', 1, null], ['TOR-004', 1, null]],
            ],
            [
                'key' => 'demo-08', 'phone' => '11991234567', 'days_ago' => 4,
                'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => PaymentMethod::PIX_MANUAL->value,
                'fulfillment_type' => 'pickup', 'scheduled_in' => 4,
                'notes' => 'Aguardando comprovante do PIX.',
                'discount_cents' => 0, 'delivery_fee_cents' => 0,
                'items' => [['BOL-002', 1, null], ['DOC-002', 1, null]],
            ],
            [
                'key' => 'demo-09', 'phone' => '11993456789', 'days_ago' => 2,
                'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => PaymentMethod::CASH_ON_DELIVERY->value,
                'fulfillment_type' => 'delivery', 'scheduled_in' => 7,
                'notes' => 'Levar troco para R$ 200,00.',
                'discount_cents' => 0, 'delivery_fee_cents' => 1200,
                'items' => [['BOL-003', 1, null], ['BOL-005', 1, null], ['DOC-004', 6, null], ['DOC-005', 6, null]],
            ],
            [
                'key' => 'demo-10', 'phone' => '11996789012', 'days_ago' => 1,
                'status' => 'confirmed', 'payment_status' => 'paid', 'payment_method' => PaymentMethod::MP_DEBIT_CARD->value,
                'fulfillment_type' => 'pickup', 'scheduled_in' => 2,
                'notes' => null,
                'discount_cents' => 500, 'delivery_fee_cents' => 0,
                'items' => [['TOR-002', 1, null], ['TOR-001', 2, null]],
            ],
        ];

        foreach ($rows as $row) {
            $exists = Order::query()
                ->forTenant($tenantId)
                ->where('meta->demo_key', $row['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($tenantId, $row, $products, $customers, $address) {
                $customer = $customers[$row['phone']];
                // Horário derivado da chave do pedido: varia entre 9h e 19h sem
                // depender de aleatoriedade, mantendo o seed reproduzível.
                $slot = (int) filter_var($row['key'], FILTER_SANITIZE_NUMBER_INT);
                $createdAt = now()->subDays($row['days_ago'])->setTime(9 + ($slot % 11), ($slot * 7) % 60);

                $lines = [];
                $subtotal = 0;

                foreach ($row['items'] as [$sku, $quantity, $notes]) {
                    $product = $products[$sku];
                    $unitPrice = (int) $product->price_cents;
                    $lineTotal = $unitPrice * $quantity;
                    $subtotal += $lineTotal;

                    $lines[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price_cents' => $unitPrice,
                        'total_cents' => $lineTotal,
                        'notes' => $notes,
                    ];
                }

                $total = $subtotal - $row['discount_cents'] + $row['delivery_fee_cents'];

                $order = Order::create(array_merge(
                    $row['fulfillment_type'] === 'delivery' ? $address : [],
                    [
                        'tenant_id' => $tenantId,
                        'customer_id' => $customer->id,
                        'status' => $row['status'],
                        'payment_status' => $row['payment_status'],
                        'payment_method' => $row['payment_method'],
                        'customer_name' => $customer->name,
                        'customer_phone' => $customer->phone,
                        'fulfillment_type' => $row['fulfillment_type'],
                        'scheduled_for' => now()->addDays($row['scheduled_in'])->setTime(14, 0),
                        'notes' => $row['notes'],
                        'subtotal_cents' => $subtotal,
                        'discount_cents' => $row['discount_cents'],
                        'delivery_fee_cents' => $row['delivery_fee_cents'],
                        'total_cents' => $total,
                        'meta' => ['demo_key' => $row['key'], 'source' => 'seeder'],
                    ]
                ));

                foreach ($lines as $line) {
                    OrderItem::create(array_merge($line, [
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                    ]));
                }

                // created_at controlado para popular o dashboard com histórico.
                $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
                OrderItem::where('order_id', $order->id)
                    ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            });
        }
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Product>  $products
     */
    private function seedShowcases(string $tenantId, array $categories, array $products): void
    {
        $rows = [
            ['slug' => 'destaques', 'name' => 'Destaques da Carol', 'type' => Showcase::TYPE_FEATURED, 'display_mode' => 'carousel', 'max_items' => 8, 'sort_order' => 1],
            ['slug' => 'bolos-de-encomenda', 'name' => 'Bolos de Encomenda', 'type' => Showcase::TYPE_CATEGORY, 'display_mode' => 'list', 'max_items' => 8, 'sort_order' => 2, 'category_slug' => 'bolos'],
            ['slug' => 'novidades', 'name' => 'Novidades', 'type' => Showcase::TYPE_RECENT, 'display_mode' => 'carousel', 'max_items' => 6, 'sort_order' => 3],
            ['slug' => 'mais-vendidos', 'name' => 'Mais Vendidos', 'type' => Showcase::TYPE_BEST_SELLERS, 'display_mode' => 'list', 'max_items' => 6, 'sort_order' => 4],
            ['slug' => 'selecao-para-festas', 'name' => 'Seleção para Festas', 'type' => Showcase::TYPE_CUSTOM, 'display_mode' => 'carousel', 'max_items' => 6, 'sort_order' => 5, 'product_skus' => ['KIT-001', 'KIT-002', 'SAL-001', 'DOC-001', 'DOC-003']],
        ];

        $available = Showcase::MAX_SHOWCASES_PER_TENANT
            - Showcase::query()->forTenant($tenantId)->whereNotIn('slug', array_column($rows, 'slug'))->count();

        foreach (array_slice($rows, 0, max($available, 0)) as $row) {
            $showcase = Showcase::updateOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'display_mode' => $row['display_mode'],
                    'category_id' => isset($row['category_slug']) ? $categories[$row['category_slug']]->id : null,
                    'max_items' => $row['max_items'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ]
            );

            if ($row['type'] === Showcase::TYPE_CUSTOM) {
                $showcase->syncProducts(
                    array_map(fn (string $sku) => $products[$sku]->id, $row['product_skus'])
                );
            }
        }
    }
}

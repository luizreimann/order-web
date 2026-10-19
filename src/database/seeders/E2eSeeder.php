<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Massa de dados DETERMINÍSTICA do Playwright (E4.5). Só serve ao banco `*_e2e`.
 *
 * NÃO está no `DatabaseSeeder` de propósito: rodar isto no banco de desenvolvimento (`wl`, com a
 * demo da Carol Doces) apagaria e recriaria dados. Por isso o seeder RECUSA qualquer banco cujo
 * nome não termine em `_e2e`.
 *
 * Determinismo (os snapshots visuais dependem disto):
 *  - slugs, UUIDs, números de pedido e datas são FIXOS (nada de `fake()`, `now()` ou `Str::uuid()`);
 *    inclusive o `created_at` dos produtos (a listagem do admin ordena por ele; ver `productCreatedAt()`);
 *  - nenhuma tela do E2E depende de `now()`: o relógio NÃO é congelado (ver e2e/support/fixtures.ts) e a
 *    loja nunca fecha sozinha por horário (`auto_close` desligado);
 *  - fonte do tema = `system` (nenhuma requisição ao Google Fonts);
 *  - Mercado Pago NÃO configurado (nenhuma chamada externa; só métodos manuais habilitados).
 *
 * Idempotente: apaga o que pertence aos dois tenants do E2E e recria. Roda com
 * `php artisan db:seed --class=E2eSeeder --force` num banco recém-migrado.
 *
 * Credenciais (só existem aqui): admin de `loja-e2e` = admin@loja-e2e.test / e2e-password;
 * admin de `loja-b-e2e` = admin@loja-b-e2e.test / e2e-password.
 */
class E2eSeeder extends Seeder
{
    public const TENANT_A_ID = 'e2e00000-0000-4000-8000-000000000001';

    public const TENANT_B_ID = 'e2e00000-0000-4000-8000-000000000002';

    public const TENANT_A_SLUG = 'loja-e2e';

    public const TENANT_B_SLUG = 'loja-b-e2e';

    public const PASSWORD = 'e2e-password';

    /** UUID fixo: `e2e<tipo>0000-0000-4000-8000-<n com 12 dígitos>`. */
    public static function id(int $kind, int $n): string
    {
        return sprintf('e2e%d0000-0000-4000-8000-%012d', $kind, $n);
    }

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_e2e')) {
            throw new RuntimeException("E2eSeeder só roda em banco *_e2e (atual: {$database}). Recusado para proteger o banco de desenvolvimento.");
        }

        DB::transaction(function () {
            $this->wipe();
            $this->seedTenantA();
            $this->seedTenantB();
        });
    }

    private function wipe(): void
    {
        $tenants = [self::TENANT_A_ID, self::TENANT_B_ID];

        foreach (['payment_webhook_events', 'payments', 'order_items', 'orders', 'showcase_product', 'showcases', 'category_product', 'products', 'categories', 'customer_addresses', 'customers', 'tenant_counters'] as $table) {
            DB::table($table)->whereIn('tenant_id', $tenants)->delete();
        }

        DB::table('users')->whereIn('tenant_id', $tenants)->delete();
        DB::table('tenants')->whereIn('id', $tenants)->delete();
    }

    private function seedTenantA(): void
    {
        $tenantId = self::TENANT_A_ID;

        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => $tenantId,
            'name' => 'Loja E2E',
            'slug' => self::TENANT_A_SLUG,
            'is_active' => true,
            'primary_color' => '#c2410c',
            'secondary_color' => '#475569',
            'settings' => [
                // Fonte `system`: sem Google Fonts (TenantTheme::DEFAULT_FONT).
                'theme' => ['font_family' => 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif'],
                'store' => [
                    'short_description' => 'Doces e salgados feitos sob encomenda.',
                    'full_description' => 'Loja usada pela suíte E2E. Todos os dados são fictícios e determinísticos.',
                    'show_about' => true,
                ],
                'contact' => [
                    'phone' => '1133334444',
                    'whatsapp' => '5511999990000',
                    'email' => 'contato@loja-e2e.test',
                    'whatsapp_message' => 'Olá! Vim pela Loja E2E.',
                    'show_whatsapp_button' => true,
                ],
                'address' => [
                    'street' => 'Rua das Flores',
                    'number' => '100',
                    'complement' => 'Loja 2',
                    'neighborhood' => 'Centro',
                    'city' => 'São Paulo',
                    'state' => 'SP',
                    'zip_code' => '01000-000',
                    'google_maps_url' => null,
                    'show_on_storefront' => true,
                ],
                // `auto_close` desligado: sem o relógio congelado, "Aberto/Fechado" não pode depender da hora do
                // dia em que a suíte roda. A loja só fecha pelo botão do admin (`paused`).
                'business_hours' => [
                    'enabled' => true,
                    'timezone' => 'America/Sao_Paulo',
                    'auto_close' => false,
                    'paused' => false,
                    'schedule' => $this->schedule(),
                ],
                'delivery' => [
                    'minimum_order_cents' => 2000,
                    'default_fee_cents' => 800,
                    'estimated_time_minutes' => 45,
                    'free_delivery_above_cents' => 15000,
                ],
                'social' => ['instagram' => 'https://instagram.com/loja-e2e'],
                'seo' => ['meta_title' => 'Loja E2E', 'meta_description' => 'Loja de testes automatizados.'],
                'payment' => ['methods' => $this->paymentMethods()],
            ],
        ])->save();

        $this->seedAdmin($tenantId, 1, 'Admin Loja E2E', 'admin@loja-e2e.test');

        $categories = $this->seedCategories($tenantId);
        $products = $this->seedProducts($tenantId, $categories);
        $customers = $this->seedCustomers($tenantId);
        $this->seedShowcases($tenantId, $categories, $products);
        $this->seedOrders($tenantId, $products, $customers);
    }

    private function seedTenantB(): void
    {
        $tenantId = self::TENANT_B_ID;

        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => $tenantId,
            'name' => 'Loja B E2E',
            'slug' => self::TENANT_B_SLUG,
            'is_active' => true,
            'primary_color' => '#0d6efd',
            'secondary_color' => '#6c757d',
            'settings' => [
                'theme' => ['font_family' => 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif'],
                'payment' => ['methods' => $this->paymentMethods()],
            ],
        ])->save();

        $this->seedAdmin($tenantId, 2, 'Admin Loja B E2E', 'admin@loja-b-e2e.test');

        $category = new Category;
        $category->forceFill([
            'id' => self::id(2, 101), 'tenant_id' => $tenantId, 'name' => 'Itens da Loja B', 'slug' => 'itens-b',
            'description' => 'Categoria exclusiva da loja B.', 'is_active' => true, 'sort_order' => 1,
        ])->save();

        foreach ([[101, 'Produto Exclusivo B', 3300], [102, 'Outro Produto B', 4400]] as [$n, $name, $price]) {
            $product = new Product;
            $product->forceFill([
                'id' => self::id(3, $n), 'tenant_id' => $tenantId, 'name' => $name, 'slug' => $n === 101 ? 'produto-exclusivo-b' : 'outro-produto-b',
                'price_cents' => $price, 'is_active' => true, 'is_featured' => false, 'sort_order' => $n,
                'track_inventory' => false,
                'created_at' => $createdAt = $this->productCreatedAt($n), 'updated_at' => $createdAt,
            ])->save();
            DB::table('category_product')->insert(['tenant_id' => $tenantId, 'category_id' => $category->id, 'product_id' => $product->id]);
        }
    }

    /**
     * `created_at` FIXO e ÚNICO por produto. Sem isto vinha de `now()` (timestamp(0)): a listagem do
     * admin ordena por `created_at desc` e os 10 produtos empatavam no mesmo segundo; se o seed cruzasse
     * a virada de um segundo a ordem das linhas mudava e o snapshot `produtos-indice` falhava (flaky).
     * A ordem abaixo (do mais novo ao mais antigo) é a que os snapshots já registravam.
     */
    private function productCreatedAt(int $n): CarbonImmutable
    {
        $newestFirst = [102, 101, 10, 1, 2, 7, 4, 5, 8, 6, 9, 3];
        $rank = array_search($n, $newestFirst, true);

        return CarbonImmutable::parse('2026-05-20 09:00:00')->addMinutes(count($newestFirst) - (int) $rank);
    }

    private function seedAdmin(string $tenantId, int $userId, string $name, string $email): void
    {
        $user = new User;
        $user->forceFill([
            'id' => $userId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => 'tenant_admin',
        ])->save();

        // Os ids de `users` foram fixados à mão: realinha a sequência (o próximo admin criado não colide).
        DB::statement("SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT MAX(id) FROM users))");
    }

    /** @return array<string, array{open: string, close: string, closed: bool}> */
    private function schedule(): array
    {
        $days = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = ['open' => '08:00', 'close' => '20:00', 'closed' => $day === 'sunday'];
        }

        return $days;
    }

    /** @return list<array<string, mixed>> */
    private function paymentMethods(): array
    {
        $methods = [];

        foreach (PaymentMethod::defaultConfig() as $method) {
            $enabled = in_array($method['id'], ['cash_on_delivery', 'pix_manual'], true);
            $method['enabled'] = $enabled;

            if ($method['id'] === 'cash_on_delivery') {
                $method['instructions'] = 'Pague em dinheiro ao receber o pedido.';
            }

            if ($method['id'] === 'pix_manual') {
                $method['instructions'] = 'Envie o comprovante pelo WhatsApp após o pagamento.';
                $method['config'] = ['pix_key' => 'pix@loja-e2e.test'];
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /** @return array<string, Category> indexado por slug */
    private function seedCategories(string $tenantId): array
    {
        $rows = [
            [1, 'bolos', 'Bolos', 'Bolos caseiros para todas as ocasiões.', true],
            [2, 'doces', 'Doces', 'Brigadeiros, brownies e docinhos.', true],
            [3, 'salgados', 'Salgados', 'Salgados para festas.', true],
            [4, 'descontinuados', 'Descontinuados', 'Categoria inativa (não aparece na loja).', false],
        ];

        $categories = [];

        foreach ($rows as [$n, $slug, $name, $description, $active]) {
            $category = new Category;
            $category->forceFill([
                'id' => self::id(2, $n), 'tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug,
                'description' => $description, 'is_active' => $active, 'sort_order' => $n,
            ])->save();

            $categories[$slug] = $category;
        }

        return $categories;
    }

    /**
     * Catálogo. `stock` = null => sem controle de estoque (sob encomenda). Quantidades escolhidas
     * para os specs: P2 (10), P3 (3: o limite é facilmente alcançável) e P4 (0: esgotado).
     *
     * @param  array<string, Category>  $categories
     * @return array<string, Product> indexado por chave curta (p1..p10)
     */
    private function seedProducts(string $tenantId, array $categories): array
    {
        // [n, chave, nome, slug, preço (centavos), estoque|null, categoria, destaque, ativo, descrição]
        $rows = [
            [1, 'p1', 'Bolo de Chocolate', 'bolo-de-chocolate', 8990, null, 'bolos', true, true, 'Bolo de chocolate com recheio de brigadeiro. Feito sob encomenda.'],
            [2, 'p2', 'Brigadeiro Gourmet', 'brigadeiro-gourmet', 450, 10, 'doces', false, true, 'Brigadeiro gourmet individual.'],
            [3, 'p3', 'Torta de Limão', 'torta-de-limao', 6500, 3, 'doces', false, true, 'Torta de limão com merengue. Últimas unidades.'],
            [4, 'p4', 'Cookie Esgotado', 'cookie-esgotado', 1200, 0, 'doces', false, true, 'Cookie de chocolate. Sem estoque no momento.'],
            [5, 'p5', 'Coxinha (cento)', 'coxinha-cento', 14990, null, 'salgados', true, true, 'Cento de coxinhas de frango.'],
            [6, 'p6', 'Pão de Mel', 'pao-de-mel', 800, 25, 'doces', false, true, 'Pão de mel recheado com doce de leite.'],
            [7, 'p7', 'Brownie', 'brownie', 1000, 12, 'doces', false, true, 'Brownie de chocolate meio amargo.'],
            [8, 'p8', 'Cupcake', 'cupcake', 700, null, 'bolos', false, true, 'Cupcake de baunilha com cobertura.'],
            [9, 'p9', 'Produto Inativo', 'produto-inativo', 1500, null, 'descontinuados', false, false, 'Não deve aparecer na loja.'],
            // Preço >= R$ 1.000: o formulário de edição o exibe com separador de milhar ("1.250,00").
            [10, 'p10', 'Bolo de Casamento', 'bolo-de-casamento', 125000, null, 'bolos', false, true, 'Bolo de três andares sob encomenda.'],
        ];

        $products = [];

        foreach ($rows as [$n, $key, $name, $slug, $price, $stock, $categorySlug, $featured, $active, $description]) {
            $product = new Product;
            $product->forceFill([
                'id' => self::id(3, $n), 'tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug,
                'sku' => sprintf('E2E-%03d', $n), 'description' => $description,
                'price_cents' => $price, 'is_active' => $active, 'is_featured' => $featured, 'sort_order' => $n,
                'track_inventory' => $stock !== null, 'stock_quantity' => $stock,
                'created_at' => $createdAt = $this->productCreatedAt($n), 'updated_at' => $createdAt,
            ])->save();

            DB::table('category_product')->insert([
                'tenant_id' => $tenantId, 'category_id' => $categories[$categorySlug]->id, 'product_id' => $product->id,
            ]);

            $products[$key] = $product;
        }

        return $products;
    }

    /** @return array<string, Customer> indexado por chave curta (c1..c3) */
    private function seedCustomers(string $tenantId): array
    {
        $rows = [
            [1, 'c1', 'Cliente E2E', 'cliente.e2e@example.test', '11988880001'],
            [2, 'c2', 'Marina Teste', 'marina.teste@example.test', '11988880002'],
            [3, 'c3', 'Paulo Exemplo', 'paulo.exemplo@example.test', '11988880003'],
        ];

        $customers = [];

        foreach ($rows as [$n, $key, $name, $email, $phone]) {
            $customer = new Customer;
            $customer->forceFill([
                'id' => self::id(5, $n), 'tenant_id' => $tenantId, 'name' => $name, 'email' => $email,
                'phone' => $phone, 'is_active' => true,
                'last_login_at' => CarbonImmutable::parse('2026-06-10 12:00:00'),
                'created_at' => CarbonImmutable::parse('2026-05-20 09:00:00'),
                'updated_at' => CarbonImmutable::parse('2026-05-20 09:00:00'),
            ])->save();

            $customers[$key] = $customer;
        }

        return $customers;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Product>  $products
     */
    private function seedShowcases(string $tenantId, array $categories, array $products): void
    {
        // [n, slug, nome, tipo, modo, categoria|null, produtos (só para custom)]
        /** @var list<array{int, string, string, string, string, string|null, list<string>}> $rows */
        $rows = [
            [1, 'destaques', 'Destaques', Showcase::TYPE_CUSTOM, 'carousel', null, ['p1', 'p2', 'p3', 'p4', 'p6', 'p7']],
            [2, 'doces-da-casa', 'Doces da Casa', Showcase::TYPE_CATEGORY, 'list', 'doces', []],
            [3, 'queridinhos', 'Queridinhos', Showcase::TYPE_FEATURED, 'list', null, []],
        ];

        foreach ($rows as [$n, $slug, $name, $type, $mode, $categorySlug, $productKeys]) {
            $showcase = new Showcase;
            $showcase->forceFill([
                'id' => self::id(4, $n), 'tenant_id' => $tenantId, 'name' => $name, 'slug' => $slug,
                'type' => $type, 'display_mode' => $mode, 'max_items' => 8, 'sort_order' => $n, 'is_active' => true,
                'category_id' => $categorySlug ? $categories[$categorySlug]->id : null,
            ])->save();

            if ($productKeys !== []) {
                $showcase->syncProducts(array_map(fn (string $key) => $products[$key]->id, $productKeys));
            }
        }
    }

    /**
     * @param  array<string, Product>  $products
     * @param  array<string, Customer>  $customers
     */
    private function seedOrders(string $tenantId, array $products, array $customers): void
    {
        // [n, cliente, status, pagamento, método, entrega, criado em, taxa, desconto, observação, itens [produto, qtd, baixa de estoque]]
        /** @var list<array{int, string, OrderStatus, PaymentStatus, PaymentMethod, string, string, int, int, string|null, list<array{string, int, int}>}> $rows */
        $rows = [
            [1, 'c1', OrderStatus::DONE, PaymentStatus::PAID, PaymentMethod::PIX_MANUAL, 'pickup', '2026-06-02 10:00:00', 0, 0, 'Retirar às 15h.', [['p1', 1, 0], ['p6', 4, 4]]],
            [2, 'c2', OrderStatus::DONE, PaymentStatus::PAID, PaymentMethod::CASH_ON_DELIVERY, 'delivery', '2026-06-05 14:30:00', 800, 0, null, [['p5', 1, 0]]],
            [3, 'c1', OrderStatus::PENDING, PaymentStatus::UNPAID, PaymentMethod::CASH_ON_DELIVERY, 'pickup', '2026-06-12 09:15:00', 0, 0, null, [['p2', 2, 2], ['p7', 3, 3]]],
            [4, 'c3', OrderStatus::CONFIRMED, PaymentStatus::PAID, PaymentMethod::PIX_MANUAL, 'delivery', '2026-06-13 16:45:00', 800, 500, 'Entregar na portaria.', [['p8', 6, 0]]],
            [5, 'c2', OrderStatus::CANCELLED, PaymentStatus::UNPAID, PaymentMethod::PIX_MANUAL, 'pickup', '2026-06-08 11:00:00', 0, 0, 'Cliente desistiu.', [['p3', 1, 0]]],
            [6, 'c3', OrderStatus::PENDING, PaymentStatus::UNPAID, PaymentMethod::CARD_ON_DELIVERY, 'pickup', '2026-06-14 18:20:00', 0, 0, null, [['p1', 2, 0]]],
        ];

        foreach ($rows as [$n, $customerKey, $status, $paymentStatus, $method, $fulfillment, $createdAt, $fee, $discount, $notes, $items]) {
            $customer = $customers[$customerKey];
            $when = CarbonImmutable::parse($createdAt);

            $subtotal = 0;
            foreach ($items as [$productKey, $quantity]) {
                $subtotal += (int) $products[$productKey]->price_cents * $quantity;
            }

            $order = new Order;
            $order->forceFill([
                'id' => self::id(6, $n), 'tenant_id' => $tenantId, 'customer_id' => $customer->id, 'number' => $n,
                'status' => $status, 'payment_status' => $paymentStatus, 'payment_method' => $method->value,
                'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
                'fulfillment_type' => $fulfillment,
                'scheduled_for' => $when->addDays(3)->setTime(14, 0),
                'notes' => $notes,
                'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'delivery_fee_cents' => $fee,
                'total_cents' => $subtotal - $discount + $fee,
                'address_line1' => $fulfillment === 'delivery' ? 'Rua das Acácias, 245' : null,
                'address_city' => $fulfillment === 'delivery' ? 'São Paulo' : null,
                'address_state' => $fulfillment === 'delivery' ? 'SP' : null,
                'address_postal_code' => $fulfillment === 'delivery' ? '05432010' : null,
                'meta' => ['source' => 'e2e-seeder'],
                'created_at' => $when, 'updated_at' => $when,
            ])->save();

            foreach ($items as $i => [$productKey, $quantity, $deducted]) {
                $product = $products[$productKey];

                $item = new OrderItem;
                $item->forceFill([
                    'id' => sprintf('e2e7%04d-0000-4000-8000-%012d', $n, $i + 1),
                    'tenant_id' => $tenantId, 'order_id' => $order->id, 'product_id' => $product->id,
                    'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity,
                    'unit_price_cents' => (int) $product->price_cents,
                    'total_cents' => (int) $product->price_cents * $quantity,
                    'stock_deducted_quantity' => $deducted,
                    'created_at' => $when, 'updated_at' => $when,
                ])->save();
            }
        }

        // O próximo pedido (criado pelo checkout) será o #7.
        DB::table('tenant_counters')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => 'orders'],
            ['value' => count($rows), 'created_at' => CarbonImmutable::parse('2026-05-20 09:00:00'), 'updated_at' => CarbonImmutable::parse('2026-06-14 18:20:00')],
        );
    }
}

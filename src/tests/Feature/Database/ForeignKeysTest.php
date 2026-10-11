<?php

namespace Tests\Feature\Database;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Database\Support\InteractsWithSchema;
use Tests\TestCase;

/**
 * E2.1 (DB-001) — integridade referencial em todo o schema, com `onDelete`
 * explícito por relação.
 *
 * Política (ver comentário das migrations):
 *  - CATÁLOGO/CONFIG/DERIVADOS do tenant (products, categories, showcases,
 *    pivôs, customer_addresses, tenant_counters, users) => CASCADE a partir do
 *    tenant: são recriáveis e não têm valor sem o tenant;
 *  - HISTÓRICO/FINANCEIRO/PII (orders, order_items, payments, customers) =>
 *    RESTRICT a partir do tenant: apagar um tenant com pedidos tem de ser uma
 *    decisão explícita (arquivar/exportar antes), nunca um efeito colateral;
 *  - pedidos NÃO somem com o cliente (`orders.customer_id` => SET NULL; o
 *    pedido guarda snapshot de nome/telefone) nem com o produto
 *    (`order_items.product_id` => SET NULL; o item guarda snapshot de nome/preço).
 */
class ForeignKeysTest extends TestCase
{
    use InteractsWithSchema, RefreshDatabase;

    public function test_every_relation_has_a_foreign_key_with_explicit_on_delete_policy(): void
    {
        $expected = [
            'users(tenant_id)' => 'tenants:c',
            'products(tenant_id)' => 'tenants:c',
            'categories(tenant_id)' => 'tenants:c',
            'customers(tenant_id)' => 'tenants:r',
            'customer_addresses(tenant_id)' => 'tenants:c',
            'customer_addresses(customer_id)' => 'customers:c',
            'orders(tenant_id)' => 'tenants:r',
            'orders(customer_id)' => 'customers:n',
            'order_items(tenant_id)' => 'tenants:r',
            'order_items(order_id)' => 'orders:c',
            'order_items(product_id)' => 'products:n',
            'payments(tenant_id)' => 'tenants:r',
            'payments(order_id)' => 'orders:r',
            'tenant_counters(tenant_id)' => 'tenants:c',
            'showcases(tenant_id)' => 'tenants:c',
            'showcases(category_id)' => 'categories:n',
            'category_product(tenant_id)' => 'tenants:c',
            'showcase_product(tenant_id)' => 'tenants:c',
        ];

        $actual = $this->foreignKeys();

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, "FK ausente: {$key}");
            $this->assertSame($value, $actual[$key], "onDelete divergente em {$key}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: callable(string): array<string, mixed>}>
     */
    public static function tablesWithTenantFk(): array
    {
        $base = fn (string $tenantId, array $extra = []) => array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);

        return [
            'products' => ['products', fn (string $t) => $base($t, ['name' => 'x', 'slug' => 'x', 'price_cents' => 1])],
            'categories' => ['categories', fn (string $t) => $base($t, ['name' => 'x', 'slug' => 'x'])],
            'customers' => ['customers', fn (string $t) => $base($t, ['name' => 'x'])],
            'orders' => ['orders', fn (string $t) => $base($t, ['number' => 1, 'customer_name' => 'x'])],
            'showcases' => ['showcases', fn (string $t) => $base($t, ['name' => 'x', 'slug' => 'x', 'type' => 'all'])],
            'users' => ['users', fn (string $t) => $base($t, [
                'id' => null, 'name' => 'x', 'email' => 'x@example.com', 'password' => 'x', 'role' => 'tenant_admin',
            ])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tablesWithTenantFk')]
    public function test_inserting_unknown_tenant_id_violates_fk(string $table, callable $row): void
    {
        $data = $row((string) Str::uuid());
        if ($data['id'] === null) {
            unset($data['id']);
        }

        $this->assertSqlState(
            self::SQLSTATE_FK_VIOLATION,
            fn () => DB::table($table)->insert($data),
            "{$table}.tenant_id aceitou tenant inexistente"
        );
    }

    public function test_children_of_orders_and_customers_reject_unknown_parents(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, function () use ($tenant) {
            Order::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => (string) Str::uuid()]);
        }, 'orders.customer_id');

        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, function () use ($tenant) {
            OrderItem::query()->create([
                'tenant_id' => $tenant->id, 'order_id' => (string) Str::uuid(), 'name' => 'x',
            ]);
        }, 'order_items.order_id');

        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, function () use ($tenant) {
            CustomerAddress::query()->create([
                'tenant_id' => $tenant->id, 'customer_id' => (string) Str::uuid(), 'line1' => 'x',
            ]);
        }, 'customer_addresses.customer_id');

        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, function () {
            DB::table('tenant_counters')->insert([
                'tenant_id' => (string) Str::uuid(), 'key' => 'order_number', 'value' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 'tenant_counters.tenant_id');
    }

    public function test_deleting_customer_does_not_delete_their_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);

        DB::table('customers')->where('id', $customer->id)->delete();

        $fresh = Order::query()->findOrFail($order->id);
        $this->assertNull($fresh->customer_id);
        $this->assertSame($order->customer_name, $fresh->customer_name);
    }

    public function test_deleting_customer_cascades_to_their_addresses(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        CustomerAddress::query()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'line1' => 'Rua A',
        ]);

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertSame(0, DB::table('customer_addresses')->count());
    }

    public function test_hard_deleting_product_keeps_order_items_with_null_product(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);
        $item = OrderItem::query()->create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'Bolo', 'quantity' => 2, 'unit_price_cents' => 1000, 'total_cents' => 2000,
        ]);

        DB::table('products')->where('id', $product->id)->delete();

        $fresh = OrderItem::query()->findOrFail($item->id);
        $this->assertNull($fresh->product_id);
        $this->assertSame('Bolo', $fresh->name);
        $this->assertSame(2000, $fresh->total_cents);
    }

    public function test_order_delete_cascades_to_items_but_is_blocked_by_payments(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);
        OrderItem::query()->create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'name' => 'x',
        ]);

        DB::table('orders')->where('id', $order->id)->delete();
        $this->assertSame(0, DB::table('order_items')->where('order_id', $order->id)->count());

        $paid = Order::factory()->create(['tenant_id' => $tenant->id]);
        DB::table('payments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'order_id' => $paid->id,
            'gateway' => 'mercadopago', 'amount_cents' => 100, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Pedido com pagamento registrado nunca pode ser apagado (restrict).
        $this->assertSqlState(
            self::SQLSTATE_FK_VIOLATION,
            fn () => DB::table('orders')->where('id', $paid->id)->delete()
        );
    }

    public function test_deleting_tenant_with_orders_is_restricted(): void
    {
        $tenant = Tenant::factory()->create();
        Order::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSqlState(
            self::SQLSTATE_FK_VIOLATION,
            fn () => DB::table('tenants')->where('id', $tenant->id)->delete()
        );
    }

    public function test_deleting_tenant_with_customers_is_restricted(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSqlState(
            self::SQLSTATE_FK_VIOLATION,
            fn () => DB::table('tenants')->where('id', $tenant->id)->delete()
        );
    }

    public function test_deleting_tenant_without_history_cascades_catalog_and_users(): void
    {
        $tenant = Tenant::factory()->create();
        Product::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::TenantAdmin]);

        DB::table('tenants')->where('id', $tenant->id)->delete();

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('users')->where('tenant_id', $tenant->id)->count());
    }

    // -----------------------------------------------------------------
    // D2 — users.tenant_id: FK, mas NULLABLE (platform_admin)
    // -----------------------------------------------------------------

    public function test_users_tenant_id_stays_nullable(): void
    {
        $this->assertTrue($this->columnIsNullable('users', 'tenant_id'), 'users.tenant_id regrediu para NOT NULL (viola D2).');
    }

    public function test_platform_admin_without_tenant_can_be_persisted(): void
    {
        $user = User::factory()->create([
            'tenant_id' => null,
            'role' => Role::PlatformAdmin,
        ]);

        $fresh = User::query()->findOrFail($user->id);
        $this->assertNull($fresh->tenant_id);
        $this->assertSame(Role::PlatformAdmin, $fresh->role);
    }

    public function test_users_tenant_id_rejects_unknown_tenant(): void
    {
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, function () {
            User::factory()->create(['tenant_id' => (string) Str::uuid(), 'role' => Role::TenantAdmin]);
        });
    }
}

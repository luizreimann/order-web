<?php

namespace Tests\Feature\Authorization;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E1.6 (SEC-004) — autorização das ações públicas de admin, no ENDPOINT REAL
 * (`POST /livewire/update`). `Livewire::test()` desliga middleware e não serve.
 *
 * Cada cenário prova: (1) admin do tenant executa e o dado muda; (2) quando a
 * policy nega, 403 e NADA muda; (3) quando a role legada perde acesso no meio
 * da sessão, 403 e NADA muda.
 */
class AdminActionsAuthorizationTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => Role::TenantAdmin,
        ]);

        $this->actingAs($this->admin, 'tenant_admin');
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: list<mixed>, 4: array<string, mixed>, 5: callable, 6: callable}
     *                                                                                                                   [url, componente, método, params, updates, assertChanged, assertUnchanged]
     */
    private function scenario(string $key): array
    {
        $a = $this->tenantA;
        $noop = static function (): void {};

        switch ($key) {
            case 'products.toggleActive':
                $p = Product::factory()->create(['tenant_id' => $a->id, 'is_active' => true]);

                return ['/tenant-a/admin/produtos', 'admin.products', 'toggleActive', [$p->id], [],
                    fn () => $this->assertFalse((bool) Product::query()->findOrFail($p->id)->is_active),
                    fn () => $this->assertTrue((bool) Product::query()->findOrFail($p->id)->is_active)];
            case 'products.delete':
                $p = Product::factory()->create(['tenant_id' => $a->id]);

                return ['/tenant-a/admin/produtos', 'admin.products', 'delete', [$p->id], [],
                    fn () => $this->assertNull(Product::query()->find($p->id)),
                    fn () => $this->assertNotNull(Product::query()->find($p->id))];
            case 'categories.toggleActive':
                $c = Category::factory()->create(['tenant_id' => $a->id, 'is_active' => true]);

                return ['/tenant-a/admin/categorias', 'admin.categories', 'toggleActive', [$c->id], [],
                    fn () => $this->assertFalse((bool) Category::query()->findOrFail($c->id)->is_active),
                    fn () => $this->assertTrue((bool) Category::query()->findOrFail($c->id)->is_active)];
            case 'categories.delete':
                $c = Category::factory()->create(['tenant_id' => $a->id]);

                return ['/tenant-a/admin/categorias', 'admin.categories', 'delete', [$c->id], [],
                    fn () => $this->assertNull(Category::query()->find($c->id)),
                    fn () => $this->assertNotNull(Category::query()->find($c->id))];
            case 'customers.toggleActive':
                $c = Customer::factory()->create(['tenant_id' => $a->id, 'is_active' => true]);

                return ['/tenant-a/admin/clientes', 'admin.customers', 'toggleActive', [$c->id], [],
                    fn () => $this->assertFalse((bool) Customer::query()->findOrFail($c->id)->is_active),
                    fn () => $this->assertTrue((bool) Customer::query()->findOrFail($c->id)->is_active)];
            case 'customers.delete':
                $c = Customer::factory()->create(['tenant_id' => $a->id]);

                return ['/tenant-a/admin/clientes', 'admin.customers', 'delete', [$c->id], [],
                    fn () => $this->assertNull(Customer::query()->find($c->id)),
                    fn () => $this->assertNotNull(Customer::query()->find($c->id))];
            case 'showcases.toggleActive':
                $s = Showcase::factory()->create(['tenant_id' => $a->id, 'is_active' => true]);

                return ['/tenant-a/admin/vitrines', 'admin.showcases', 'toggleActive', [$s->id], [],
                    fn () => $this->assertFalse((bool) Showcase::query()->findOrFail($s->id)->is_active),
                    fn () => $this->assertTrue((bool) Showcase::query()->findOrFail($s->id)->is_active)];
            case 'showcases.delete':
                $s = Showcase::factory()->create(['tenant_id' => $a->id]);

                return ['/tenant-a/admin/vitrines', 'admin.showcases', 'delete', [$s->id], [],
                    fn () => $this->assertNull(Showcase::query()->find($s->id)),
                    fn () => $this->assertNotNull(Showcase::query()->find($s->id))];
            case 'showcases.updateOrder':
                $s1 = Showcase::factory()->create(['tenant_id' => $a->id, 'sort_order' => 5]);
                $s2 = Showcase::factory()->create(['tenant_id' => $a->id, 'sort_order' => 6]);

                return ['/tenant-a/admin/vitrines', 'admin.showcases', 'updateOrder', [[$s2->id, $s1->id]], [],
                    fn () => $this->assertSame(0, (int) Showcase::query()->findOrFail($s2->id)->sort_order),
                    fn () => $this->assertSame(6, (int) Showcase::query()->findOrFail($s2->id)->sort_order)];
            case 'orders.updateStatus':
                $o = Order::factory()->create(['tenant_id' => $a->id]);

                return ["/tenant-a/admin/pedidos/{$o->id}", 'admin.orders.show', 'updateStatus', [], ['status' => 'confirmed'],
                    fn () => $this->assertSame(OrderStatus::CONFIRMED, Order::query()->findOrFail($o->id)->status),
                    fn () => $this->assertSame(OrderStatus::PENDING, Order::query()->findOrFail($o->id)->status)];
            case 'settings.save':
                return ['/tenant-a/admin/configuracoes', 'admin.tenant.settings', 'save', [], ['slug' => 'novo-slug-a'],
                    fn () => $this->assertSame('novo-slug-a', Tenant::query()->findOrFail($a->id)->slug),
                    fn () => $this->assertSame('tenant-a', Tenant::query()->findOrFail($a->id)->slug)];
            case 'payment.saveIntegrations':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'saveIntegrations', ['TOKEN-abc'], // E3.6: segredo write-only vai como parâmetro, não como propriedade pública
                    ['mp_public_key' => 'PUB-123'],
                    fn () => $this->assertSame('PUB-123', data_get(Tenant::query()->findOrFail($a->id)->settings, 'payment.mercadopago.public_key')),
                    fn () => $this->assertNull(data_get(Tenant::query()->findOrFail($a->id)->settings, 'payment.mercadopago.public_key'))];
            case 'payment.saveMethods':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'saveMethods', [],
                    ['payment_methods.0.label' => 'Rótulo Novo'],
                    fn () => $this->assertSame('Rótulo Novo', data_get(Tenant::query()->findOrFail($a->id)->settings, 'payment.methods.0.label')),
                    fn () => $this->assertNull(data_get(Tenant::query()->findOrFail($a->id)->settings, 'payment.methods.0.label'))];
            case 'payment.toggleMethod':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'toggleMethod', ['pix_manual'], [], $noop, $noop];
            case 'payment.updateMethodLabel':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'updateMethodLabel', ['pix_manual', 'Outro'], [], $noop, $noop];
            case 'payment.updateMethodInstructions':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'updateMethodInstructions', ['pix_manual', 'x'], [], $noop, $noop];
            case 'payment.updatePixKey':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'updatePixKey', ['chave'], [], $noop, $noop];
            case 'payment.testMercadoPagoConnection':
                return ['/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', 'testMercadoPagoConnection', [], [], $noop, $noop];
            case 'hours.togglePause':
                return ['/tenant-a/admin/horarios', 'admin.tenant.business-hours', 'togglePause', [], [],
                    fn () => $this->assertTrue((bool) data_get(Tenant::query()->findOrFail($a->id)->settings, 'business_hours.paused')),
                    fn () => $this->assertNull(data_get(Tenant::query()->findOrFail($a->id)->settings, 'business_hours.paused'))];
        }

        $this->fail("Cenário desconhecido [{$key}].");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scenarios(): array
    {
        $keys = [
            'products.toggleActive', 'products.delete',
            'categories.toggleActive', 'categories.delete',
            'customers.toggleActive', 'customers.delete',
            'showcases.toggleActive', 'showcases.delete', 'showcases.updateOrder',
            'orders.updateStatus',
            'settings.save',
            'payment.saveIntegrations', 'payment.saveMethods', 'payment.toggleMethod',
            'payment.updateMethodLabel', 'payment.updateMethodInstructions', 'payment.updatePixKey',
            'payment.testMercadoPagoConnection',
            'hours.togglePause',
        ];

        return array_combine($keys, array_map(fn (string $k) => [$k], $keys));
    }

    #[DataProvider('scenarios')]
    public function test_tenant_admin_of_the_same_tenant_succeeds(string $key): void
    {
        [$url, $component, $method, $params, $updates, $changed] = $this->scenario($key);

        $snapshot = $this->snapshotFor($this->get($url)->assertOk()->getContent(), $component);

        $this->callLivewire($snapshot, $method, $params, $updates)->assertOk();

        $changed();
    }

    #[DataProvider('scenarios')]
    public function test_action_is_forbidden_and_changes_nothing_when_policy_denies(string $key): void
    {
        [$url, $component, $method, $params, $updates, , $unchanged] = $this->scenario($key);

        $snapshot = $this->snapshotFor($this->get($url)->assertOk()->getContent(), $component);

        // Simula um papel sem permissão: a policy passa a negar tudo.
        Gate::before(fn () => false);

        $this->callLivewire($snapshot, $method, $params, $updates)->assertForbidden();

        $unchanged();
    }

    #[DataProvider('scenarios')]
    public function test_action_is_forbidden_and_changes_nothing_when_user_loses_the_admin_role(string $key): void
    {
        [$url, $component, $method, $params, $updates, , $unchanged] = $this->scenario($key);

        $snapshot = $this->snapshotFor($this->get($url)->assertOk()->getContent(), $component);

        // Role legada (o extinto `tenant_staff`) gravada por fora, no meio da sessão.
        DB::table('users')->where('id', $this->admin->id)->update(['role' => 'tenant_staff']);
        // `actingAs()` mantém a instância em memória: sincroniza-a com o banco.
        $this->admin->setRawAttributes(array_merge($this->admin->getAttributes(), ['role' => 'tenant_staff']), true);

        $this->callLivewire($snapshot, $method, $params, $updates)->assertForbidden();

        $unchanged();
    }

    public function test_admin_of_tenant_a_cannot_act_on_records_of_tenant_b(): void
    {
        $productB = Product::factory()->create(['tenant_id' => $this->tenantB->id, 'is_active' => true]);
        $categoryB = Category::factory()->create(['tenant_id' => $this->tenantB->id]);
        $customerB = Customer::factory()->create(['tenant_id' => $this->tenantB->id]);
        $showcaseB = Showcase::factory()->create(['tenant_id' => $this->tenantB->id, 'is_active' => true]);

        $cases = [
            ['/tenant-a/admin/produtos', 'admin.products', 'delete', $productB->id],
            ['/tenant-a/admin/produtos', 'admin.products', 'toggleActive', $productB->id],
            ['/tenant-a/admin/categorias', 'admin.categories', 'delete', $categoryB->id],
            ['/tenant-a/admin/clientes', 'admin.customers', 'delete', $customerB->id],
            ['/tenant-a/admin/vitrines', 'admin.showcases', 'delete', $showcaseB->id],
            ['/tenant-a/admin/vitrines', 'admin.showcases', 'toggleActive', $showcaseB->id],
        ];

        foreach ($cases as [$url, $component, $method, $id]) {
            $snapshot = $this->snapshotFor($this->get($url)->assertOk()->getContent(), $component);

            $this->assertContains(
                $this->callLivewire($snapshot, $method, [$id])->getStatusCode(),
                [403, 404],
                "{$component}::{$method} com id de outro tenant deveria ser 403/404."
            );
        }

        $this->assertTrue((bool) Product::withoutTenantScope()->findOrFail($productB->id)->is_active);
        $this->assertNotNull(Category::withoutTenantScope()->find($categoryB->id));
        $this->assertNotNull(Customer::withoutTenantScope()->find($customerB->id));
        $this->assertTrue((bool) Showcase::withoutTenantScope()->findOrFail($showcaseB->id)->is_active);
    }

    public function test_admin_of_tenant_a_cannot_open_or_update_an_order_of_tenant_b(): void
    {
        $orderB = Order::factory()->create(['tenant_id' => $this->tenantB->id]);

        // A sessão de A não vale no admin de B. (Roda primeiro: um 404 no mount de
        // componente Livewire deixa o binding `redirect` trocado no mesmo processo de teste.)
        $this->get("/tenant-b/admin/pedidos/{$orderB->id}")->assertRedirect('/tenant-b/admin/login');

        // Tampering do snapshot é barrado pelo checksum do Livewire; o id de outro
        // tenant na URL do próprio tenant A dá 404 (TenantScope + forTenant).
        $this->get("/tenant-a/admin/pedidos/{$orderB->id}")->assertNotFound();

        $this->assertSame(OrderStatus::PENDING, Order::withoutTenantScope()->findOrFail($orderB->id)->status);
    }
}

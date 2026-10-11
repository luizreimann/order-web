<?php

namespace Tests\Feature\Tenancy;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\Feature\Tenancy\Support\TenantProbeComponent;
use Tests\TestCase;

/**
 * ACH-01 / ACH-02 — o `POST /livewire/update` não re-executa o middleware da
 * rota original.
 *
 * O Livewire só re-aplica middleware que esteja numa allowlist estática
 * (`PersistentMiddleware::$persistentMiddleware`, vendor linhas 14-23).
 * Sem `Livewire::addPersistentMiddleware()`, nem `ResolveTenantFromPath` nem
 * `EnsureTenantAdminAuthenticated` rodam em nenhuma interação do Livewire —
 * logo o `TenantContext` fica vazio (global scope não filtra) e ações de admin
 * executam sem revalidar a sessão.
 *
 * Estes testes batem no ENDPOINT HTTP REAL. `Livewire::test()` não serve aqui:
 * ele desabilita middleware explicitamente
 * (vendor/.../SupportTesting/SubsequentRender.php:35 —
 * `temporarilyDisableExceptionHandlingAndMiddleware`).
 */
class LivewirePersistentMiddlewareTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);

        $this->productA = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Produto do A',
            'is_active' => true,
        ]);

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Produto do B',
            'is_active' => true,
        ]);

        Livewire::component('tenant-probe', TenantProbeComponent::class);

        // Rota de sonda com EXATAMENTE a mesma pilha das rotas públicas de
        // tenant (`web` + `tenant`), definida aqui para não tocar routes/web.php.
        Route::middleware(['web', 'tenant'])
            ->get('{tenant:slug}/__probe', fn (Tenant $tenant) => Blade::render(
                '<div>@livewire(\'tenant-probe\', [\'tenant\' => $tenant])</div>',
                ['tenant' => $tenant]
            ))
            ->name('tests.tenant.probe');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function loginAsAdminOf(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => \App\Enums\Role::TenantAdmin,
        ]);

        // Login real pelo endpoint (o guard grava a chave de sessão do tenant
        // e regenera a sessão) — nada de escrever chave crua na sessão.
        $this->post('/'.$tenant->slug.'/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        return $user;
    }

    // ------------------------------------------------------------------
    // A) isolamento de tenant durante o POST /livewire/update
    // ------------------------------------------------------------------

    public function test_tenant_context_is_populated_during_a_livewire_update_request(): void
    {
        $html = $this->get('/tenant-a/__probe')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'tenant-probe');

        // O GET acima populou o contexto no container compartilhado do teste.
        // Zera para que o POST tenha de resolvê-lo por conta própria — é o que
        // acontece em produção, onde cada request tem container novo.
        app(TenantContext::class)->forget();

        $data = $this->returnedData($this->callLivewire($snapshot, 'probe')->assertOk());

        $this->assertTrue(
            $data['contextHasTenant'] ?? false,
            'ACH-01: o TenantContext está VAZIO no POST /livewire/update — '
            .'ResolveTenantFromPath não foi re-executado, então o TenantScope não filtra nada.'
        );

        $this->assertSame($this->tenantA->id, $data['contextTenantId'] ?? null);
    }

    public function test_livewire_action_without_for_tenant_cannot_see_other_tenants_data(): void
    {
        $html = $this->get('/tenant-a/__probe')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'tenant-probe');

        app(TenantContext::class)->forget();

        $data = $this->returnedData($this->callLivewire($snapshot, 'probe')->assertOk());

        $this->assertSame(
            1,
            $data['visibleProducts'] ?? null,
            'ACH-01: uma consulta sem ->forTenant() dentro de uma ação Livewire enxergou '
            .'produtos de OUTRO tenant (vazamento cross-tenant).'
        );
    }

    // ------------------------------------------------------------------
    // B) autorização de ação destrutiva de admin
    // ------------------------------------------------------------------

    public function test_admin_destructive_action_is_rejected_without_a_valid_admin_session(): void
    {
        $this->loginAsAdminOf($this->tenantA);

        $html = $this->get('/tenant-a/admin/produtos')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'admin.products');

        // Sessão perdida/expirada/roubada: o snapshot continua válido (é
        // assinado com a APP_KEY, não com a sessão).
        $this->flushSession();
        app(TenantContext::class)->forget();

        $response = $this->callLivewire($snapshot, 'delete', [$this->productA->id]);

        $this->assertNotNull(
            Product::withoutTenantScope()->find($this->productA->id),
            'ACH-02: o produto foi DELETADO por um POST /livewire/update sem sessão de admin — '
            .'EnsureTenantAdminAuthenticated não foi re-executado. Status: '.$response->status()
        );

        $this->assertTrue(
            $response->isRedirect() || $response->status() === 403 || $response->status() === 419,
            'ACH-02: `Admin\Products\Index::delete()` executou via POST /livewire/update SEM '
            .'sessão de admin. Status recebido: '.$response->status()
        );
    }

    public function test_admin_destructive_action_still_works_with_a_valid_admin_session(): void
    {
        $this->loginAsAdminOf($this->tenantA);

        $html = $this->get('/tenant-a/admin/produtos')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'admin.products');

        app(TenantContext::class)->forget();

        $this->callLivewire($snapshot, 'delete', [$this->productA->id])->assertOk();

        $this->assertNull(
            Product::withoutTenantScope()->find($this->productA->id),
            'a ação legítima de admin deveria ter deletado o produto'
        );
        $this->assertNotNull(
            Product::withoutTenantScope()->find($this->productB->id),
            'a ação não deveria tocar o produto de outro tenant'
        );
    }

    // ------------------------------------------------------------------
    // C) componentes públicos não podem ser afetados pela correção
    // ------------------------------------------------------------------

    public function test_public_cart_component_keeps_working_without_any_login(): void
    {
        $this->withSession([
            'cart.'.$this->tenantA->id => [
                ['product_id' => $this->productA->id, 'qty' => 1],
            ],
        ]);

        $html = $this->get('/tenant-a/carrinho')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'tenant.cart');

        app(TenantContext::class)->forget();

        $response = $this->callLivewire($snapshot, 'increment', [$this->productA->id])->assertOk();

        // E3.1: as linhas do carrinho deixaram de ser estado público do componente (eram
        // editáveis pelo cliente); agora são computadas a partir da sessão. A prova de que
        // a ação funcionou sem login é a fonte de verdade (a sessão) e o HTML re-renderizado.
        $cart = session()->get('cart.'.$this->tenantA->id);
        $this->assertSame(
            2,
            is_array($cart) ? ($cart[0]['qty'] ?? null) : null,
            'a ação do carrinho (componente público, sem login) deveria continuar funcionando'
        );

        $expectedSubtotal = 'R$ '.number_format(2 * $this->productA->price_cents / 100, 2, ',', '.');
        $this->assertStringContainsString($expectedSubtotal, (string) $response->json('components.0.effects.html'));
    }

    // ------------------------------------------------------------------
    // D) guarda de configuração
    // ------------------------------------------------------------------

    public function test_tenant_middlewares_are_registered_as_persistent(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(\App\Http\Middleware\ResolveTenantFromPath::class, $persistent);
        $this->assertContains(\App\Http\Middleware\EnsureTenantAdminAuthenticated::class, $persistent);
    }

    /**
     * A nuance que torna a correção segura: o Livewire re-aplica apenas os
     * middlewares da ROTA ORIGINAL do componente, filtrados pela allowlist
     * (`PersistentMiddleware::getApplicablePersistentMiddleware()`, vendor:136).
     * Logo `tenant.auth` nunca vaza para componentes públicos, e a ordem da
     * rota original é preservada.
     */
    public function test_only_middleware_of_the_original_route_is_reapplied(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $reapplied = function (string $uri) use ($persistent): array {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "rota [{$uri}] não encontrada");

            return array_values(array_intersect(
                array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'),
                $persistent
            ));
        };

        // Rota de admin: tenant primeiro, tenant.auth depois (ele depende do
        // TenantContext, populado por ResolveTenantFromPath).
        $this->assertSame([
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolveTenantFromPath::class,
            \App\Http\Middleware\EnsureTenantAdminAuthenticated::class,
        ], $reapplied('{tenant}/admin/produtos'));

        // Storefront público: NUNCA recebe a exigência de sessão de admin.
        foreach (['{tenant}', '{tenant}/carrinho', '{tenant}/checkout', '{tenant}/p/{productSlug}'] as $uri) {
            $this->assertSame([
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\ResolveTenantFromPath::class,
            ], $reapplied($uri), "rota pública [{$uri}] ganhou middleware de admin");
        }
    }
}

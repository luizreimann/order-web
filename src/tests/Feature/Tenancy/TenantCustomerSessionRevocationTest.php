<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E1.4 / SEC — revogação de sessão do cliente vs. `POST /livewire/update`.
 *
 * Dois fatos combinados produziam um bypass de escrita:
 *
 * 1. `TenantCustomerAuth` não constava em `Livewire::addPersistentMiddleware()`,
 *    logo não rodava em nenhuma interação subsequente do componente;
 * 2. o Livewire re-hidrata `public Model $prop` a partir do snapshot **sem
 *    global scopes** (`SupportModels/ModelSynth.php:65` e
 *    `SupportLegacyModels/EloquentModelSynth.php:207` →
 *    `Model::newQueryForRestoration()`, `Model.php:1669` →
 *    `newQueryWithoutScopes()`), e `Model::save()` de instância também escreve
 *    via `newModelQuery()` (`Model.php:1219`/`1608`), sem scopes.
 *
 * Resultado: `Tenant\Account\Profile::save()` escrevia sobre o `Customer` que
 * veio do snapshot sem NUNCA revalidar o cookie de sessão. `mount()` valida,
 * mas `mount()` não roda em requests de update.
 *
 * Os testes abaixo batem no endpoint HTTP real — `Livewire::test()` desabilita
 * middleware e por isso não serve para testar segurança.
 */
class TenantCustomerSessionRevocationTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private string $sessionToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);

        $this->sessionToken = app(CustomerAuthService::class)->generateSessionToken();

        $this->customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nome Original',
            'email' => 'original@example.com',
            'phone' => '11999990000',
            'session_token' => $this->sessionToken,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Carrega `/{tenant}/conta/perfil` autenticado e devolve o snapshot do
     * componente, como o navegador do cliente o teria em mãos.
     */
    private function authenticatedProfileSnapshot(): string
    {
        $cookieName = app(CustomerAuthService::class)->getCookieName($this->tenant);

        $html = $this->withCredentials()
            ->withCookie($cookieName, $this->sessionToken)
            ->get('/tenant-a/conta/perfil')
            ->assertOk()
            ->getContent();

        return $this->snapshotFor((string) $html, 'tenant.account.profile');
    }

    /**
     * Replay do payload de update capturado, tentando alterar o perfil.
     */
    private function replaySave(string $snapshot): \Illuminate\Testing\TestResponse
    {
        return $this->callLivewire($snapshot, 'save', [], [
            'name' => 'Nome Invadido',
            'email' => 'invadido@example.com',
            'phone' => '11888887777',
        ]);
    }

    private function freshCustomer(): Customer
    {
        $customer = Customer::withoutTenantScope()->findOrFail($this->customer->id);

        $this->assertInstanceOf(Customer::class, $customer);

        return $customer;
    }

    private function assertProfileUnchanged(string $because): void
    {
        $customer = $this->freshCustomer();

        $this->assertSame('Nome Original', $customer->name, $because);
        $this->assertSame('original@example.com', $customer->email, $because);
        $this->assertSame('11999990000', $customer->phone, $because);
    }

    // ------------------------------------------------------------------
    // A) o bypass
    // ------------------------------------------------------------------

    public function test_profile_save_is_rejected_after_logout_revokes_the_session_token(): void
    {
        $snapshot = $this->authenticatedProfileSnapshot();

        // Logout (aqui ou em outro dispositivo): o token deixa de existir.
        app(CustomerAuthService::class)->logout($this->tenant, $this->sessionToken);
        app(TenantContext::class)->forget();

        $response = $this->replaySave($snapshot);

        $this->assertProfileUnchanged(
            'BYPASS: o perfil foi ALTERADO por um POST /livewire/update após o logout '
            .'ter revogado o session_token. Status: '.$response->status()
        );
    }

    public function test_profile_save_is_rejected_when_the_session_cookie_is_gone(): void
    {
        $snapshot = $this->authenticatedProfileSnapshot();

        // Cookie expirado/rotacionado: o payload capturado continua válido,
        // porque o checksum do snapshot é assinado com a APP_KEY, não com a
        // sessão do cliente.
        $this->withCookie(
            app(CustomerAuthService::class)->getCookieName($this->tenant),
            'token-expirado-que-nao-existe-mais'
        );
        app(TenantContext::class)->forget();

        $response = $this->replaySave($snapshot);

        $this->assertProfileUnchanged(
            'BYPASS: o perfil foi ALTERADO por um POST /livewire/update SEM nenhum cookie '
            .'de sessão de cliente. Status: '.$response->status()
        );
    }

    public function test_profile_save_is_rejected_after_the_customer_is_deactivated(): void
    {
        $snapshot = $this->authenticatedProfileSnapshot();

        Customer::withoutTenantScope()
            ->whereKey($this->customer->id)
            ->update(['is_active' => false]);

        app(TenantContext::class)->forget();

        $response = $this->replaySave($snapshot);

        $this->assertProfileUnchanged(
            'BYPASS: o perfil foi ALTERADO por um POST /livewire/update mesmo com o '
            .'customer DESATIVADO (is_active = false). Status: '.$response->status()
        );
    }

    public function test_another_customers_session_cannot_save_over_the_snapshot_customer(): void
    {
        $snapshot = $this->authenticatedProfileSnapshot();

        // Um segundo cliente do MESMO tenant, com sessão própria e válida.
        $otherToken = app(CustomerAuthService::class)->generateSessionToken();
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outro Cliente',
            'phone' => '11777776666',
            'session_token' => $otherToken,
            'is_active' => true,
        ]);

        $cookieName = app(CustomerAuthService::class)->getCookieName($this->tenant);
        $this->withCredentials()->withCookie($cookieName, $otherToken);
        app(TenantContext::class)->forget();

        $response = $this->replaySave($snapshot);

        $this->assertProfileUnchanged(
            'BYPASS: um cliente autenticado escreveu no perfil de OUTRO cliente apenas '
            .'reenviando o snapshot dele. Status: '.$response->status()
        );
    }

    // ------------------------------------------------------------------
    // B) o caminho legítimo não pode regredir
    // ------------------------------------------------------------------

    public function test_profile_save_still_works_with_a_valid_customer_session(): void
    {
        $snapshot = $this->authenticatedProfileSnapshot();

        app(TenantContext::class)->forget();

        $this->replaySave($snapshot)->assertOk();

        $customer = $this->freshCustomer();

        $this->assertSame('Nome Invadido', $customer->name, 'a ação legítima deveria ter salvo o nome');
        $this->assertSame('invadido@example.com', $customer->email);
        $this->assertSame('11888887777', $customer->phone);
    }

    public function test_account_index_does_not_render_for_a_revoked_session(): void
    {
        $cookieName = app(CustomerAuthService::class)->getCookieName($this->tenant);

        $html = $this->withCredentials()
            ->withCookie($cookieName, $this->sessionToken)
            ->get('/tenant-a/conta')
            ->assertOk()
            ->getContent();

        // `App\Livewire\Tenant\Account\Index` é registrado como `tenant.account`
        // (o Livewire suprime o sufixo `Index`, ComponentRegistry.php:98).
        $snapshot = $this->snapshotFor((string) $html, 'tenant.account');

        app(CustomerAuthService::class)->logout($this->tenant, $this->sessionToken);
        app(TenantContext::class)->forget();

        $response = $this->refreshLivewire($snapshot);

        $this->assertSame(
            403,
            $response->status(),
            'o componente de conta continuou renderizando dados do cliente após a revogação da sessão'
        );
    }

    // ------------------------------------------------------------------
    // C) guardas de configuração
    // ------------------------------------------------------------------

    public function test_tenant_customer_auth_is_registered_as_persistent_middleware(): void
    {
        $this->assertContains(
            \App\Http\Middleware\TenantCustomerAuth::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware()
        );
    }

    /**
     * `TenantCustomerAuth` depende de `$request->route('tenant')`, resolvido por
     * `SubstituteBindings`, e o tenant ativo vem de `ResolveTenantFromPath`.
     * O Livewire re-aplica os middlewares na ORDEM da rota original
     * (`gatherRouteMiddleware`, vendor PersistentMiddleware.php:142), então a
     * ordem efetiva no `POST /livewire/update` é a asseverada aqui.
     */
    public function test_customer_auth_runs_after_tenant_resolution_on_the_account_routes(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        foreach (['{tenant}/conta', '{tenant}/conta/perfil'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route, "rota [{$uri}] não encontrada");

            $reapplied = array_values(array_intersect(
                array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'),
                $persistent
            ));

            $this->assertSame([
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\ResolveTenantFromPath::class,
                \App\Http\Middleware\TenantCustomerAuth::class,
            ], $reapplied, "ordem de middleware persistente incorreta em [{$uri}]");
        }
    }
}

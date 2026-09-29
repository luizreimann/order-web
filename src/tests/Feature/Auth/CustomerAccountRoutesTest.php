<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\TenantCustomerAuth;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E1.5 — `tenant.customer.auth` bloqueia.
 */
class CustomerAccountRoutesTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);

        $this->token = app(CustomerAuthService::class)->generateSessionToken();
        Customer::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'session_token' => $this->token,
        ]);
    }

    private function cookieName(Tenant $tenant): string
    {
        return app(CustomerAuthService::class)->getCookieName($tenant);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountUris(): array
    {
        return ['conta' => ['/tenant-a/conta'], 'perfil' => ['/tenant-a/conta/perfil']];
    }

    #[DataProvider('accountUris')]
    public function test_account_routes_redirect_without_cookie(string $uri): void
    {
        $this->get($uri)
            ->assertRedirect('/tenant-a')
            ->assertSessionHas('error');
    }

    #[DataProvider('accountUris')]
    public function test_account_routes_are_ok_with_a_valid_cookie(string $uri): void
    {
        $this->withCredentials()->withCookie($this->cookieName($this->tenantA), $this->token)
            ->get($uri)->assertOk();
    }

    #[DataProvider('accountUris')]
    public function test_account_routes_redirect_with_a_cookie_of_another_tenant(string $uri): void
    {
        // O token do cliente A no cookie do tenant B (e vice-versa).
        $this->withCredentials()->withCookie($this->cookieName($this->tenantB), $this->token)
            ->get(str_replace('tenant-a', 'tenant-b', $uri))
            ->assertRedirect('/tenant-b');

        $this->withCredentials()->withCookie($this->cookieName($this->tenantB), $this->token)
            ->get($uri)
            ->assertRedirect('/tenant-a');
    }

    public function test_invalid_cookie_is_cleared_on_redirect(): void
    {
        $this->withCredentials()->withCookie($this->cookieName($this->tenantA), 'lixo')
            ->get('/tenant-a/conta')
            ->assertRedirect('/tenant-a')
            ->assertCookieExpired($this->cookieName($this->tenantA));
    }

    public function test_livewire_update_of_account_component_without_session_is_403(): void
    {
        $html = $this->withCredentials()->withCookie($this->cookieName($this->tenantA), $this->token)
            ->get('/tenant-a/conta/perfil')->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'tenant.account.profile');

        $this->withCookie($this->cookieName($this->tenantA), 'expirado');
        app(\App\Support\TenantContext::class)->forget();

        $this->callLivewire($snapshot, 'save', [], ['name' => 'X'])->assertForbidden();
    }

    public function test_no_route_of_the_account_group_reaches_the_component_unauthenticated(): void
    {
        $checked = 0;

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), '{tenant}/conta')) {
                continue;
            }

            $checked++;
            $this->assertContains(TenantCustomerAuth::class, array_filter(
                app('router')->gatherRouteMiddleware($route),
                'is_string'
            ), $route->uri());

            $uri = str_replace('{tenant}', 'tenant-a', $route->uri());
            $this->get('/'.$uri)->assertRedirect('/tenant-a');
        }

        $this->assertGreaterThanOrEqual(2, $checked);
    }

    public function test_public_storefront_does_not_require_customer_login_and_clears_a_dead_cookie(): void
    {
        $this->get('/tenant-a')->assertOk();

        $this->withCredentials()->withCookie($this->cookieName($this->tenantA), 'lixo')
            ->get('/tenant-a')
            ->assertOk()
            ->assertCookieExpired($this->cookieName($this->tenantA));
    }
}

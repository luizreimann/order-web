<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Auth\Support\CustomerProbeComponent;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E1.4 — guard `customer` (cookie `customer_session_{tenant}`).
 */
class CustomerGuardTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Customer $customerA;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);

        $this->token = app(CustomerAuthService::class)->generateSessionToken();
        $this->customerA = Customer::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'phone' => '11999990000',
            'session_token' => $this->token,
        ]);

        Livewire::component('customer-probe', CustomerProbeComponent::class);

        Route::middleware(['web', 'tenant'])
            ->get('{tenant:slug}/__customer_probe', fn (Tenant $tenant) => Blade::render(
                '<div>@livewire(\'customer-probe\', [\'tenant\' => $tenant])</div>',
                ['tenant' => $tenant]
            ));
    }

    private function cookie(Tenant $tenant): string
    {
        return app(CustomerAuthService::class)->getCookieName($tenant);
    }

    public function test_guard_user_is_available_on_the_page(): void
    {
        $response = $this->withCredentials()
            ->withCookie($this->cookie($this->tenantA), $this->token)
            ->get('/tenant-a/__customer_probe')
            ->assertOk();

        $response->assertSee('customer:'.$this->customerA->id, false);
        $this->assertSame($this->customerA->id, Auth::guard('customer')->user()?->getAuthIdentifier());
    }

    public function test_guard_user_is_available_on_livewire_update(): void
    {
        $html = $this->withCredentials()
            ->withCookie($this->cookie($this->tenantA), $this->token)
            ->get('/tenant-a/__customer_probe')->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'customer-probe');

        app(TenantContext::class)->forget();

        $data = $this->returnedData($this->callLivewire($snapshot, 'probe')->assertOk());

        $this->assertSame($this->customerA->id, $data['seenCustomerId'] ?? null);
    }

    public function test_guard_is_empty_without_cookie(): void
    {
        $this->get('/tenant-a/__customer_probe')->assertOk()->assertDontSee('customer:'.$this->customerA->id, false);

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_guard_rejects_a_cookie_of_another_tenant(): void
    {
        // Token do cliente A apresentado no cookie do tenant B.
        $this->withCredentials()
            ->withCookie($this->cookie($this->tenantB), $this->token)
            ->get('/tenant-b/__customer_probe')
            ->assertOk();

        $this->assertNull(Auth::guard('customer')->user());
    }

    public function test_guard_rejects_inactive_customer_and_revoked_token(): void
    {
        app(TenantContext::class)->set($this->tenantA);
        $this->withCredentials()->withCookie($this->cookie($this->tenantA), $this->token);
        $this->get('/tenant-a/__customer_probe');
        $this->assertNotNull(Auth::guard('customer')->user());

        // Revogação DENTRO da mesma app: o guard não pode servir cache velho.
        $this->customerA->forceFill(['is_active' => false])->save();
        $this->get('/tenant-a/__customer_probe');
        $this->assertNull(Auth::guard('customer')->user());

        $this->customerA->forceFill(['is_active' => true, 'session_token' => null])->save();
        $this->get('/tenant-a/__customer_probe');
        $this->assertNull(Auth::guard('customer')->user());
    }

    /**
     * Marcador para o futuro OTP (decisão D3, risco aceito): hoje o telefone,
     * sozinho e sem verificação de posse, emite uma sessão válida. Quando o
     * OTP entrar, ESTE teste deve passar a falhar — e ser invertido.
     */
    public function test_d3_accepted_risk_phone_alone_authenticates(): void
    {
        $result = app(CustomerAuthService::class)->login($this->tenantA, '(11) 99999-0000');

        $this->assertSame($this->customerA->id, $result['customer']->id);

        $this->withCredentials()
            ->withCookie($this->cookie($this->tenantA), $result['session_token'])
            ->get('/tenant-a/__customer_probe');

        $this->assertSame($this->customerA->id, Auth::guard('customer')->user()?->getAuthIdentifier());
    }
}

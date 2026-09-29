<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E1.4 — guard `tenant_admin` (sessão do Laravel, escopado por tenant).
 */
class TenantAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);
    }

    private function adminOf(Tenant $tenant, string $email): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
            'email' => $email,
        ]);
    }

    private function postLogin(string $slug, string $email, string $password = 'password')
    {
        return $this->post("/{$slug}/admin/login", ['email' => $email, 'password' => $password]);
    }

    public function test_valid_admin_login_authenticates_through_the_guard(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');

        $this->postLogin('tenant-a', 'a@example.com')->assertRedirect('/tenant-a/admin');

        $this->get('/tenant-a/admin')->assertOk();

        $this->assertTrue(Auth::guard('tenant_admin')->check());
        $this->assertSame('a@example.com', Auth::guard('tenant_admin')->user()?->email);
    }

    public function test_login_does_not_use_the_legacy_raw_session_key(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');

        $this->postLogin('tenant-a', 'a@example.com');

        $this->assertNull(session('tenant_admin_user_id'));
        $this->assertNotEmpty(
            array_filter(array_keys(session()->all()), fn ($k) => str_starts_with((string) $k, 'login_tenant_admin_')),
            'a sessão deveria conter a chave do guard (login_tenant_admin_*)'
        );
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');

        $this->postLogin('tenant-a', 'a@example.com', 'errada')->assertSessionHasErrors('email');
        $this->get('/tenant-a/admin')->assertRedirect('/tenant-a/admin/login');
    }

    public function test_admin_of_tenant_a_is_rejected_on_tenant_b(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');

        // Não consegue logar no B
        $this->postLogin('tenant-b', 'a@example.com')->assertSessionHasErrors('email');

        // Logado no A, não entra no B
        $this->postLogin('tenant-a', 'a@example.com');
        $this->get('/tenant-a/admin')->assertOk();
        $this->get('/tenant-b/admin')->assertRedirect('/tenant-b/admin/login');
    }

    public function test_acting_as_a_user_of_another_tenant_is_rejected(): void
    {
        $userA = $this->adminOf($this->tenantA, 'a@example.com');

        $this->actingAs($userA, 'tenant_admin');

        $this->get('/tenant-b/admin')->assertRedirect('/tenant-b/admin/login');
        $this->get('/tenant-a/admin')->assertOk();
    }

    public function test_two_tenants_can_be_logged_in_simultaneously_in_the_same_browser(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');
        $this->adminOf($this->tenantB, 'b@example.com');

        $this->postLogin('tenant-a', 'a@example.com');
        $this->postLogin('tenant-b', 'b@example.com');

        $this->get('/tenant-a/admin')->assertOk();
        $this->get('/tenant-b/admin')->assertOk();

        // Logout em A não derruba B.
        $this->post('/tenant-a/admin/logout');

        $this->get('/tenant-a/admin')->assertRedirect('/tenant-a/admin/login');
        $this->get('/tenant-b/admin')->assertOk();
    }

    public function test_guard_resolves_the_user_of_the_current_tenant_in_each_request(): void
    {
        $this->adminOf($this->tenantA, 'a@example.com');
        $this->adminOf($this->tenantB, 'b@example.com');
        $this->postLogin('tenant-a', 'a@example.com');
        $this->postLogin('tenant-b', 'b@example.com');

        $context = app(TenantContext::class);

        $context->set($this->tenantA);
        $this->assertSame('a@example.com', Auth::guard('tenant_admin')->user()?->email);

        $context->set($this->tenantB);
        $this->assertSame('b@example.com', data_get(Auth::guard('tenant_admin')->user(), 'email'));
    }

    public function test_platform_admin_without_tenant_persists_and_resolves_without_error(): void
    {
        $platform = User::factory()->create([
            'tenant_id' => null,
            'role' => Role::PlatformAdmin,
        ]);

        $fresh = User::query()->findOrFail($platform->id);
        $this->assertNull($fresh->tenant_id);
        $this->assertSame(Role::PlatformAdmin, $fresh->role);

        // Sem impersonation, não autentica em rota de tenant — e sem erro.
        $this->actingAs($fresh, 'tenant_admin');
        $this->get('/tenant-a/admin')->assertRedirect('/tenant-a/admin/login');

        app(TenantContext::class)->set($this->tenantA);
        $this->assertNull(Auth::guard('tenant_admin')->user());
        $this->assertFalse(Auth::guard('tenant_admin')->check());
    }

    public function test_platform_admin_cannot_login_through_the_tenant_login_form(): void
    {
        User::factory()->create([
            'tenant_id' => null,
            'role' => Role::PlatformAdmin,
            'email' => 'root@example.com',
        ]);

        $this->postLogin('tenant-a', 'root@example.com')->assertSessionHasErrors('email');
    }

    public function test_tenant_staff_is_no_longer_accepted(): void
    {
        DB::table('users')->insert([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantA->id,
            'role' => 'tenant_staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postLogin('tenant-a', 'staff@example.com')->assertSessionHasErrors('email');

        // Mesmo com a sessão já contendo o id (login antigo), o guard recusa.
        $staff = User::query()->where('email', 'staff@example.com')->firstOrFail();
        $this->actingAs($staff, 'tenant_admin');
        $this->get('/tenant-a/admin')->assertRedirect('/tenant-a/admin/login');
    }

    public function test_admin_middleware_fails_closed_when_there_is_no_tenant_in_context(): void
    {
        $middleware = app(\App\Http\Middleware\EnsureTenantAdminAuthenticated::class);
        app(TenantContext::class)->forget();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $middleware->handle(\Illuminate\Http\Request::create('/x'), fn () => response('ok'));
    }
}

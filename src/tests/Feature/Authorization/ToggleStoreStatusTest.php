<?php

namespace Tests\Feature\Authorization;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * E1.6 — `POST {tenant}/admin/toggle-store-status` (antes uma closure sem checagem).
 */
class ToggleStoreStatusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
    }

    private function paused(): mixed
    {
        return data_get(Tenant::query()->findOrFail($this->tenant->id)->settings, 'business_hours.paused');
    }

    public function test_tenant_admin_toggles_and_the_json_contract_is_kept(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');

        $this->postJson('/tenant-a/admin/toggle-store-status')
            ->assertOk()
            ->assertExactJson(['success' => true, 'paused' => true]);
        $this->assertTrue($this->paused());

        $this->postJson('/tenant-a/admin/toggle-store-status')
            ->assertOk()
            ->assertExactJson(['success' => true, 'paused' => false]);

        $this->assertSame('tenant.admin.toggle-store-status', app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/tenant-a/admin/toggle-store-status', 'POST')
        )->getName());
    }

    public function test_without_session_is_blocked_and_changes_nothing(): void
    {
        $this->postJson('/tenant-a/admin/toggle-store-status')->assertRedirect('/tenant-a/admin/login');

        $this->assertNull($this->paused());
    }

    public function test_platform_admin_is_forbidden(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'role' => Role::PlatformAdmin]);
        $this->actingAs($platform, 'tenant_admin');

        $this->assertContains($this->postJson('/tenant-a/admin/toggle-store-status')->getStatusCode(), [302, 403]);
        $this->assertNull($this->paused());
    }

    public function test_denied_by_policy_returns_403_and_changes_nothing(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');

        Gate::before(fn () => false);

        $this->postJson('/tenant-a/admin/toggle-store-status')->assertForbidden();
        $this->assertNull($this->paused());
    }
}

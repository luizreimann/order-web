<?php

namespace Tests\Feature\Authorization;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E1.6 — matriz ator × ability × model (mesmo tenant / outro tenant).
 *
 * `platform_admin` SEM impersonation não acessa recurso de tenant nesta sprint
 * (impersonation é a Sprint 07).
 */
class PoliciesMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->b = Tenant::factory()->create(['slug' => 'tenant-b']);
    }

    /**
     * @return array<string, array{class-string, list<string>}>
     */
    public static function modelAbilities(): array
    {
        $crud = ['view', 'update', 'delete'];

        return [
            'product' => [Product::class, $crud],
            'category' => [Category::class, $crud],
            'customer' => [Customer::class, $crud],
            'showcase' => [Showcase::class, $crud],
            'order' => [Order::class, ['view', 'update']],
        ];
    }

    private function make(string $class, Tenant $tenant): object
    {
        return match ($class) {
            Product::class => Product::factory()->make(['tenant_id' => $tenant->id]),
            Category::class => Category::factory()->make(['tenant_id' => $tenant->id]),
            Customer::class => Customer::factory()->make(['tenant_id' => $tenant->id]),
            Showcase::class => Showcase::factory()->make(['tenant_id' => $tenant->id]),
            Order::class => Order::factory()->make(['tenant_id' => $tenant->id]),
            Payment::class => (new Payment)->forceFill(['tenant_id' => $tenant->id]),
            default => throw new \InvalidArgumentException($class),
        };
    }

    private function actor(?Role $role, ?Tenant $tenant): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenant?->id]);
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $abilities
     */
    #[DataProvider('modelAbilities')]
    public function test_model_policies_matrix(string $class, array $abilities): void
    {
        $tenantAdminA = $this->actor(Role::TenantAdmin, $this->a);
        $tenantAdminB = $this->actor(Role::TenantAdmin, $this->b);
        $platform = $this->actor(Role::PlatformAdmin, null);
        $platformWithTenant = $this->actor(Role::PlatformAdmin, $this->a);
        $orphan = $this->actor(Role::TenantAdmin, null);

        $ownModel = $this->make($class, $this->a);
        $otherModel = $this->make($class, $this->b);

        foreach ($abilities as $ability) {
            $this->assertTrue(Gate::forUser($tenantAdminA)->allows($ability, $ownModel), "{$class}::{$ability} admin A / modelo A");
            $this->assertFalse(Gate::forUser($tenantAdminA)->allows($ability, $otherModel), "{$class}::{$ability} admin A / modelo B");
            $this->assertFalse(Gate::forUser($tenantAdminB)->allows($ability, $ownModel), "{$class}::{$ability} admin B / modelo A");
            $this->assertFalse(Gate::forUser($platform)->allows($ability, $ownModel), "{$class}::{$ability} platform_admin");
            $this->assertFalse(Gate::forUser($platformWithTenant)->allows($ability, $ownModel), "{$class}::{$ability} platform_admin com tenant_id");
            $this->assertFalse(Gate::forUser($orphan)->allows($ability, $ownModel), "{$class}::{$ability} tenant_admin sem tenant");
        }

        // viewAny / create recebem o Tenant de contexto.
        foreach (['viewAny', 'create'] as $ability) {
            if ($class === Order::class && $ability === 'create') {
                continue;
            }
            $this->assertTrue(Gate::forUser($tenantAdminA)->allows($ability, [$class, $this->a]));
            $this->assertFalse(Gate::forUser($tenantAdminA)->allows($ability, [$class, $this->b]));
            $this->assertFalse(Gate::forUser($platform)->allows($ability, [$class, $this->a]));
        }
    }

    public function test_tenant_policy_matrix(): void
    {
        $adminA = $this->actor(Role::TenantAdmin, $this->a);
        $platform = $this->actor(Role::PlatformAdmin, null);

        foreach (['view', 'manageSettings', 'updateSlug', 'manageIntegrations', 'managePaymentMethods', 'toggleStoreStatus'] as $ability) {
            $this->assertTrue(Gate::forUser($adminA)->allows($ability, $this->a), "Tenant::{$ability} próprio");
            $this->assertFalse(Gate::forUser($adminA)->allows($ability, $this->b), "Tenant::{$ability} de outro tenant");
            $this->assertFalse(Gate::forUser($platform)->allows($ability, $this->a), "Tenant::{$ability} platform_admin");
        }
    }

    public function test_payment_policy_matrix(): void
    {
        $adminA = $this->actor(Role::TenantAdmin, $this->a);
        $platform = $this->actor(Role::PlatformAdmin, null);

        $this->assertTrue(Gate::forUser($adminA)->allows('viewAny', [Payment::class, $this->a]));
        $this->assertFalse(Gate::forUser($adminA)->allows('viewAny', [Payment::class, $this->b]));
        $this->assertFalse(Gate::forUser($platform)->allows('viewAny', [Payment::class, $this->a]));
        $this->assertTrue(Gate::forUser($adminA)->allows('view', $this->make(Payment::class, $this->a)));
        $this->assertFalse(Gate::forUser($adminA)->allows('view', $this->make(Payment::class, $this->b)));
    }

    public function test_guest_and_legacy_role_are_denied(): void
    {
        $product = $this->make(Product::class, $this->a);

        $this->assertTrue(Gate::forUser(null)->denies('view', $product));

        $legacy = User::factory()->create(['tenant_id' => $this->a->id, 'role' => Role::TenantAdmin]);
        \DB::table('users')->where('id', $legacy->id)->update(['role' => 'tenant_staff']);
        $legacy = User::query()->findOrFail($legacy->id);

        $this->assertTrue(Gate::forUser($legacy)->denies('view', $product));
    }

    public function test_role_is_the_single_place_that_decides_permissions(): void
    {
        $this->assertTrue(Role::TenantAdmin->can(\App\Enums\Permission::UpdateStoreSlug));
        $this->assertFalse(Role::PlatformAdmin->can(\App\Enums\Permission::UpdateStoreSlug));
        $this->assertFalse(Role::PlatformAdmin->requiresTenant());
    }

    public function test_default_gate_resolves_the_tenant_admin_guard_user(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->a->id, 'role' => Role::TenantAdmin]);

        app(TenantContext::class)->set($this->a);
        Auth::guard('tenant_admin')->setUser($admin);

        // O guard default é `web` (sem usuário): o Gate precisa enxergar o admin mesmo assim.
        $this->assertNull(Auth::guard('web')->user());
        $this->assertTrue(Gate::allows('view', $this->make(Product::class, $this->a)));
        $this->assertTrue(Gate::inspect('view', $this->make(Product::class, $this->b))->denied());
        $this->assertInstanceOf(Response::class, Gate::inspect('view', $this->make(Product::class, $this->a)));
    }
}

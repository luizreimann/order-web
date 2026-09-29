<?php

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    public function test_context_is_registered_as_scoped_not_singleton(): void
    {
        // Duas resoluções dentro do mesmo "escopo" devolvem a mesma instância...
        $this->assertSame($this->context, app(TenantContext::class));

        $this->context->set($this->tenantA);

        // ...mas o escopo é reciclado ao final da requisição (em Octane), o que
        // o Laravel expõe via forgetScopedInstances().
        app()->forgetScopedInstances();

        $fresh = app(TenantContext::class);

        $this->assertNotSame($this->context, $fresh);
        $this->assertNull($fresh->id());
    }

    public function test_starts_empty(): void
    {
        $this->assertNull($this->context->get());
        $this->assertNull($this->context->id());
        $this->assertFalse($this->context->hasTenant());
        $this->assertFalse($this->context->isPlatform());
    }

    public function test_set_and_get_with_model(): void
    {
        $this->context->set($this->tenantA);

        $this->assertTrue($this->context->hasTenant());
        $this->assertSame($this->tenantA->id, $this->context->id());
        $this->assertSame($this->tenantA, $this->context->get());
    }

    public function test_set_with_id_lazy_loads_the_model(): void
    {
        $this->context->set($this->tenantA->id);

        $this->assertSame($this->tenantA->id, $this->context->id());

        $loaded = $this->context->get();

        $this->assertInstanceOf(Tenant::class, $loaded);
        $this->assertSame($this->tenantA->id, $loaded->id);
    }

    public function test_forget_clears_everything(): void
    {
        $this->context->set($this->tenantA);
        $this->context->forget();

        $this->assertFalse($this->context->hasTenant());
        $this->assertNull($this->context->id());
        $this->assertFalse($this->context->isPlatform());
    }

    public function test_platform_mode_has_no_tenant_but_is_flagged(): void
    {
        $this->context->set($this->tenantA);
        $this->context->usePlatformScope();

        $this->assertTrue($this->context->isPlatform());
        $this->assertFalse($this->context->hasTenant());
        $this->assertNull($this->context->id());
    }

    public function test_set_leaves_platform_mode(): void
    {
        $this->context->usePlatformScope();
        $this->context->set($this->tenantA);

        $this->assertFalse($this->context->isPlatform());
        $this->assertTrue($this->context->hasTenant());
    }

    public function test_run_for_switches_and_restores_context(): void
    {
        $this->context->set($this->tenantA);

        $inside = $this->context->runFor($this->tenantB, fn () => $this->context->id());

        $this->assertSame($this->tenantB->id, $inside);
        $this->assertSame($this->tenantA->id, $this->context->id());
    }

    public function test_run_for_restores_context_even_when_callback_throws(): void
    {
        $this->context->set($this->tenantA);

        try {
            $this->context->runFor($this->tenantB, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($this->tenantA->id, $this->context->id());
    }

    public function test_run_for_null_runs_without_tenant_and_restores(): void
    {
        $this->context->set($this->tenantA);

        $inside = $this->context->runFor(null, fn () => $this->context->id());

        $this->assertNull($inside);
        $this->assertSame($this->tenantA->id, $this->context->id());
    }

    public function test_run_for_platform_restores_previous_tenant(): void
    {
        $this->context->set($this->tenantA);

        $inside = $this->context->runForPlatform(fn () => [
            $this->context->isPlatform(),
            $this->context->id(),
        ]);

        $this->assertSame([true, null], $inside);
        $this->assertFalse($this->context->isPlatform());
        $this->assertSame($this->tenantA->id, $this->context->id());
    }

    public function test_run_for_nests(): void
    {
        $this->context->set($this->tenantA);

        $seen = $this->context->runFor($this->tenantB, fn () => $this->context->runFor(
            $this->tenantA,
            fn () => $this->context->id()
        ).'|'.$this->context->id());

        $this->assertSame($this->tenantA->id.'|'.$this->tenantB->id, $seen);
        $this->assertSame($this->tenantA->id, $this->context->id());
    }

    public function test_resolve_returns_the_container_instance(): void
    {
        $this->assertSame($this->context, TenantContext::resolve());
    }
}

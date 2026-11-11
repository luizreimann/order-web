<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Critério #3 da Sprint 06: o isolamento de tenant não é só filtro de LEITURA. Com um tenant no
 * contexto, nenhum model de OUTRO tenant pode ser gravado, apagado ou restaurado por instância, e
 * ninguém consegue mover uma linha para outro tenant, nem criar uma já em outro tenant. Também
 * cobre a re-hidratação do Livewire (`newQueryForRestoration`), que antes ignorava o scope.
 */
class TenantWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::factory()->create(['slug' => 'loja-a']);
        $this->b = Tenant::factory()->create(['slug' => 'loja-b']);
    }

    /** @return array<string, array{class-string<Model>, string}> modelo e um atributo editável */
    public static function ownedModels(): array
    {
        return [
            'Product' => [Product::class, 'name'],
            'Category' => [Category::class, 'name'],
            'Customer' => [Customer::class, 'name'],
            'Showcase' => [Showcase::class, 'name'],
            'Order' => [Order::class, 'customer_name'],
        ];
    }

    private function makeFor(string $class, Tenant $tenant): Model
    {
        return $class::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function actAs(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
    }

    private function raw(string $class, string $id, string $column): mixed
    {
        return $class::withoutTenantScope()->withoutGlobalScopes(['Illuminate\Database\Eloquent\SoftDeletingScope'])->whereKey($id)->value($column);
    }

    #[DataProvider('ownedModels')]
    public function test_updating_another_tenants_instance_is_refused_and_the_row_is_untouched(string $class, string $attr): void
    {
        $foreign = $this->makeFor($class, $this->b);
        $before = $this->raw($class, $foreign->getKey(), $attr);
        $this->actAs($this->a);

        try {
            $foreign->forceFill([$attr => 'HACKED'])->save();
            $this->fail('escrever em model de outro tenant não pode funcionar');
        } catch (CrossTenantWriteException) {
        }

        $this->assertSame($before, $this->raw($class, $foreign->getKey(), $attr));
    }

    #[DataProvider('ownedModels')]
    public function test_deleting_another_tenants_instance_is_refused(string $class, string $attr): void
    {
        $foreign = $this->makeFor($class, $this->b);
        $this->actAs($this->a);

        $this->expectException(CrossTenantWriteException::class);

        try {
            $foreign->delete();
        } finally {
            $this->assertNotNull($this->raw($class, $foreign->getKey(), 'id'), 'a linha continua existindo');
        }
    }

    #[DataProvider('ownedModels')]
    public function test_moving_an_own_row_to_another_tenant_is_refused(string $class, string $attr): void
    {
        $own = $this->makeFor($class, $this->a);
        $this->actAs($this->a);

        try {
            $own->forceFill(['tenant_id' => $this->b->id])->save();
            $this->fail('mover linha para outro tenant não pode funcionar');
        } catch (CrossTenantWriteException) {
        }

        $this->assertSame($this->a->id, $this->raw($class, $own->getKey(), 'tenant_id'));
    }

    #[DataProvider('ownedModels')]
    public function test_creating_a_row_already_in_another_tenant_is_refused(string $class, string $attr): void
    {
        $this->actAs($this->a);
        $before = $class::withoutTenantScope()->count();

        try {
            $class::factory()->create(['tenant_id' => $this->b->id]);
            $this->fail('criar em outro tenant não pode funcionar');
        } catch (CrossTenantWriteException) {
        }

        $this->assertSame($before, $class::withoutTenantScope()->count());
    }

    public function test_restoring_or_force_deleting_a_soft_deleted_row_of_another_tenant_is_refused(): void
    {
        /** @var Product $foreign */
        $foreign = $this->makeFor(Product::class, $this->b);
        $foreign->delete();
        $this->actAs($this->a);

        try {
            $foreign->restore();
            $this->fail('restore cross-tenant');
        } catch (CrossTenantWriteException) {
        }

        try {
            $foreign->forceDelete();
            $this->fail('forceDelete cross-tenant');
        } catch (CrossTenantWriteException) {
        }

        $this->assertNotNull(Product::withoutTenantScope()->withTrashed()->whereKey($foreign->getKey())->value('deleted_at'));
    }

    #[DataProvider('ownedModels')]
    public function test_the_same_tenant_writes_normally(string $class, string $attr): void
    {
        $own = $this->makeFor($class, $this->a);
        $this->actAs($this->a);

        $own->forceFill([$attr => 'ok'])->save();
        $this->assertSame('ok', $this->raw($class, $own->getKey(), $attr));

        $own->delete();
        $this->assertNull($class::query()->whereKey($own->getKey())->first(), 'sumiu da leitura do próprio tenant');
    }

    #[DataProvider('ownedModels')]
    public function test_outside_a_tenant_context_nothing_changes_webhooks_seeders_and_commands_still_write(string $class, string $attr): void
    {
        $foreign = $this->makeFor($class, $this->b);
        $this->assertFalse(app(TenantContext::class)->hasTenant());

        $foreign->forceFill([$attr => 'job'])->save();

        $this->assertSame('job', $this->raw($class, $foreign->getKey(), $attr));
    }

    public function test_the_platform_context_is_not_restricted(): void
    {
        $foreign = $this->makeFor(Product::class, $this->b);
        app(TenantContext::class)->usePlatformScope();

        $foreign->forceFill(['name' => 'plataforma'])->save();

        $this->assertSame('plataforma', $this->raw(Product::class, $foreign->getKey(), 'name'));
    }

    public function test_run_for_switches_the_write_boundary_with_the_tenant(): void
    {
        $foreign = $this->makeFor(Product::class, $this->b);
        $this->actAs($this->a);

        app(TenantContext::class)->runFor($this->b, function () use ($foreign) {
            $foreign->forceFill(['name' => 'impersonation'])->save();
        });

        $this->assertSame('impersonation', $this->raw(Product::class, $foreign->getKey(), 'name'));

        $this->expectException(CrossTenantWriteException::class);
        $foreign->forceFill(['name' => 'depois'])->save();
    }

    #[DataProvider('ownedModels')]
    public function test_livewire_rehydration_does_not_find_another_tenants_row(string $class, string $attr): void
    {
        $foreign = $this->makeFor($class, $this->b);
        $own = $this->makeFor($class, $this->a);
        $this->actAs($this->a);

        // O que o `ModelSynth` do Livewire executa ao re-hidratar `public Model $x` do snapshot.
        $this->assertNull((new $class)->newQueryForRestoration($foreign->getKey())->first(), 'outro tenant: não encontra');
        $this->assertSame($own->getKey(), (new $class)->newQueryForRestoration($own->getKey())->value('id'), 'o próprio tenant: encontra');
    }

    public function test_rehydration_without_a_tenant_context_still_works_for_queued_jobs(): void
    {
        $product = $this->makeFor(Product::class, $this->a);

        $this->assertSame($product->getKey(), (new Product)->newQueryForRestoration($product->getKey())->value('id'));
    }
}

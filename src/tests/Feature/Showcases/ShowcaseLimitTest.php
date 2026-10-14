<?php

namespace Tests\Feature\Showcases;

use App\Enums\Role;
use App\Exceptions\ShowcaseLimitReachedException;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * VAL-004 — regra do limite de vitrines (caminho sem concorrência) e o formulário admin.
 * A corrida está em `ShowcaseLimitConcurrencyTest`.
 */
class ShowcaseLimitTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
    }

    private function data(string $name): array
    {
        return ['name' => $name, 'type' => Showcase::TYPE_FEATURED, 'display_mode' => 'list', 'max_items' => 8, 'is_active' => true];
    }

    public function test_creates_until_the_limit_then_refuses_without_writing(): void
    {
        for ($i = 1; $i <= Showcase::MAX_SHOWCASES_PER_TENANT; $i++) {
            $created = Showcase::createWithinLimit($this->tenant->id, $this->data("Vitrine {$i}"));
            $this->assertSame($i, (int) $created->sort_order);
            $this->assertSame($this->tenant->id, $created->tenant_id);
        }

        try {
            Showcase::createWithinLimit($this->tenant->id, $this->data('Excedente'));
            $this->fail('A 11ª vitrine deveria ser recusada.');
        } catch (ShowcaseLimitReachedException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }

        $this->assertSame(10, Showcase::query()->forTenant($this->tenant->id)->count());
    }

    public function test_soft_deleted_showcases_free_a_slot(): void
    {
        $all = Showcase::factory()->count(Showcase::MAX_SHOWCASES_PER_TENANT)->create(['tenant_id' => $this->tenant->id]);

        $all->first()->delete();

        $created = Showcase::createWithinLimit($this->tenant->id, $this->data('Nova'));
        $this->assertTrue($created->exists);
        $this->assertSame(10, Showcase::query()->forTenant($this->tenant->id)->count());
    }

    public function test_limit_is_per_tenant(): void
    {
        $other = Tenant::factory()->create();
        Showcase::factory()->count(Showcase::MAX_SHOWCASES_PER_TENANT)->create(['tenant_id' => $other->id]);

        $created = Showcase::createWithinLimit($this->tenant->id, $this->data('De outro tenant'));

        $this->assertSame($this->tenant->id, $created->tenant_id);
    }

    public function test_unknown_tenant_is_rejected(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Showcase::createWithinLimit('00000000-0000-0000-0000-000000000000', $this->data('Órfã'));
    }

    public function test_admin_form_refuses_the_eleventh_showcase_through_the_real_endpoint(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');
        Showcase::factory()->count(Showcase::MAX_SHOWCASES_PER_TENANT)->create(['tenant_id' => $this->tenant->id]);

        $page = $this->get('/tenant-a/admin/vitrines/nova')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $page, 'admin.showcases.form');

        $response = $this->callLivewire($snapshot, 'save', [], ['name' => 'Décima primeira'])->assertOk();

        $this->assertSame(10, Showcase::query()->forTenant($this->tenant->id)->count());
        $this->assertNull(Showcase::query()->where('name', 'Décima primeira')->first());
        $this->assertNull($response->json('components.0.effects.redirect'), 'recusada, não deve redirecionar como sucesso');
    }

    public function test_admin_form_still_creates_below_the_limit(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');
        Showcase::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'sort_order' => 7]);

        $page = $this->get('/tenant-a/admin/vitrines/nova')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $page, 'admin.showcases.form');

        $this->callLivewire($snapshot, 'save', [], ['name' => 'Quarta'])->assertOk();

        $created = Showcase::query()->where('name', 'Quarta')->firstOrFail();
        $this->assertSame(8, (int) $created->sort_order);
        $this->assertSame($this->tenant->id, $created->tenant_id);
    }
}

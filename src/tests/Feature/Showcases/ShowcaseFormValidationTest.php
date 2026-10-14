<?php

namespace Tests\Feature\Showcases;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Evidência executável para itens herdados da sprint 03 (`03-showcases/04-qa-review.md`):
 * BUG-003 (categoria obrigatória), VAL-001/VAL-002 (tenant em categoria e produtos) e a
 * coerência de `max_items` (D5) entre a validação PHP e o teto do model.
 *
 * Bate no endpoint HTTP real do Livewire, como os demais testes de admin.
 */
class ShowcaseFormValidationTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->other = Tenant::factory()->create(['slug' => 'tenant-b', 'is_active' => true]);

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array{0: \Illuminate\Testing\TestResponse, 1: bool} resposta e se criou a vitrine
     */
    private function save(array $updates): array
    {
        $page = $this->get('/tenant-a/admin/vitrines/nova')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $page, 'admin.showcases.form');

        $response = $this->callLivewire($snapshot, 'save', [], ['name' => 'Vitrine de teste'] + $updates)->assertOk();

        return [$response, Showcase::query()->where('name', 'Vitrine de teste')->exists()];
    }

    public function test_category_type_requires_a_category(): void
    {
        [, $created] = $this->save(['type' => Showcase::TYPE_CATEGORY]);

        $this->assertFalse($created, 'BUG-003: vitrine por categoria sem categoria foi criada');
    }

    public function test_category_from_another_tenant_is_rejected(): void
    {
        $foreign = Category::factory()->create(['tenant_id' => $this->other->id]);

        [, $created] = $this->save(['type' => Showcase::TYPE_CATEGORY, 'category_id' => $foreign->id]);

        $this->assertFalse($created, 'VAL-001: categoria de outro tenant aceita');
    }

    public function test_own_category_is_accepted(): void
    {
        $own = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        [, $created] = $this->save(['type' => Showcase::TYPE_CATEGORY, 'category_id' => $own->id]);

        $this->assertTrue($created, 'controle: categoria do próprio tenant deveria ser aceita');
    }

    public function test_products_from_another_tenant_are_rejected(): void
    {
        $foreign = Product::factory()->create(['tenant_id' => $this->other->id]);

        [, $created] = $this->save(['type' => Showcase::TYPE_CUSTOM, 'selectedProducts' => [$foreign->id]]);

        $this->assertFalse($created, 'VAL-002: produto de outro tenant aceito');
    }

    public function test_own_products_are_accepted(): void
    {
        $own = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        [, $created] = $this->save(['type' => Showcase::TYPE_CUSTOM, 'selectedProducts' => [$own->id]]);

        $this->assertTrue($created, 'controle: produto do próprio tenant deveria ser aceito');
    }

    public function test_max_items_validation_is_within_the_all_type_ceiling(): void
    {
        foreach ([0, 51, 500] as $invalid) {
            [, $created] = $this->save(['type' => Showcase::TYPE_ALL, 'max_items' => $invalid]);
            $this->assertFalse($created, "max_items={$invalid} deveria ser recusado pelo formulário");
        }

        [, $created] = $this->save(['type' => Showcase::TYPE_ALL, 'max_items' => 50]);
        $this->assertTrue($created);

        // O maior valor aceito pelo formulário (50) cabe no teto do model (D5) e vale de fato.
        Product::factory()->count(55)->create(['tenant_id' => $this->tenant->id]);
        $showcase = Showcase::query()->where('name', 'Vitrine de teste')->firstOrFail();
        $this->assertSame(50, $showcase->max_items);
        $this->assertCount(50, $showcase->getProducts());
    }
}

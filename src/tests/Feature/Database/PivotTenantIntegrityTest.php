<?php

namespace Tests\Feature\Database;

use App\Enums\Role;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Livewire\Admin\Showcases\Form as ShowcaseForm;
use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Database\Support\InteractsWithSchema;
use Tests\TestCase;

/**
 * E2.2 (DB-002) — pivôs com `tenant_id` e isolamento garantido NO BANCO.
 *
 * Mecanismo: `unique (tenant_id, id)` nas tabelas pai (products, categories,
 * showcases) + FK COMPOSTA nos pivôs `(tenant_id, X_id) -> X (tenant_id, id)`.
 * Assim, uma linha de pivô só existe se o produto E a categoria/vitrine
 * pertencem ao MESMO `tenant_id` gravado no pivô — sem trigger, sem CHECK.
 */
class PivotTenantIntegrityTest extends TestCase
{
    use InteractsWithSchema, RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->b = Tenant::factory()->create(['slug' => 'tenant-b']);
    }

    // -----------------------------------------------------------------
    // Banco
    // -----------------------------------------------------------------

    public function test_pivot_tables_have_tenant_id_column_not_null(): void
    {
        foreach (['category_product', 'showcase_product'] as $table) {
            $this->assertNotNull($this->columnType($table, 'tenant_id'), "{$table}.tenant_id ausente");
            $this->assertFalse($this->columnIsNullable($table, 'tenant_id'));
        }
    }

    public function test_pivot_foreign_keys_are_composite_with_tenant(): void
    {
        $fks = $this->foreignKeys();

        $this->assertSame('categories:c', $fks['category_product(tenant_id,category_id)'] ?? null);
        $this->assertSame('products:c', $fks['category_product(tenant_id,product_id)'] ?? null);
        $this->assertSame('showcases:c', $fks['showcase_product(tenant_id,showcase_id)'] ?? null);
        $this->assertSame('products:c', $fks['showcase_product(tenant_id,product_id)'] ?? null);
    }

    public function test_db_rejects_category_product_link_across_tenants(): void
    {
        $productA = Product::factory()->create(['tenant_id' => $this->a->id]);
        $categoryB = Category::factory()->create(['tenant_id' => $this->b->id]);

        // tenant_id do pivô = A (casa com o produto, não com a categoria)
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => DB::table('category_product')->insert([
            'tenant_id' => $this->a->id, 'category_id' => $categoryB->id, 'product_id' => $productA->id,
        ]), 'pivô com tenant do produto');

        // tenant_id do pivô = B (casa com a categoria, não com o produto)
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => DB::table('category_product')->insert([
            'tenant_id' => $this->b->id, 'category_id' => $categoryB->id, 'product_id' => $productA->id,
        ]), 'pivô com tenant da categoria');
    }

    public function test_db_rejects_showcase_product_link_across_tenants(): void
    {
        $productA = Product::factory()->create(['tenant_id' => $this->a->id]);
        $showcaseB = Showcase::factory()->custom()->create(['tenant_id' => $this->b->id]);

        foreach ([$this->a->id, $this->b->id] as $tenantId) {
            $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => DB::table('showcase_product')->insert([
                'tenant_id' => $tenantId, 'showcase_id' => $showcaseB->id, 'product_id' => $productA->id, 'sort_order' => 0,
            ]), "pivô com tenant_id {$tenantId}");
        }
    }

    public function test_db_rejects_duplicate_pivot_rows(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->a->id]);
        $category = Category::factory()->create(['tenant_id' => $this->a->id]);
        $row = ['tenant_id' => $this->a->id, 'category_id' => $category->id, 'product_id' => $product->id];

        DB::table('category_product')->insert($row);

        $this->assertSqlState(self::SQLSTATE_UNIQUE_VIOLATION, fn () => DB::table('category_product')->insert($row));
    }

    // -----------------------------------------------------------------
    // Models: o pivô nasce com o tenant do modelo
    // -----------------------------------------------------------------

    public function test_attach_via_relations_fills_pivot_tenant_id_from_the_model(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->a->id]);
        $category = Category::factory()->create(['tenant_id' => $this->a->id]);
        $showcase = Showcase::factory()->custom()->create(['tenant_id' => $this->a->id]);

        // Fora de qualquer contexto de tenant (seeder/job): o valor vem do model.
        $product->syncCategories([$category->id]);
        $showcase->syncProducts([$product->id]);

        $this->assertSame($this->a->id, DB::table('category_product')->value('tenant_id'));
        $this->assertSame($this->a->id, DB::table('showcase_product')->value('tenant_id'));

        // E a relação inversa também preenche.
        $other = Product::factory()->create(['tenant_id' => $this->a->id]);
        $category->products()->attach($other->id);
        $this->assertSame(2, DB::table('category_product')->where('tenant_id', $this->a->id)->count());
    }

    public function test_attaching_cross_tenant_via_relation_is_rejected_by_the_database(): void
    {
        $productA = Product::factory()->create(['tenant_id' => $this->a->id]);
        $categoryB = Category::factory()->create(['tenant_id' => $this->b->id]);
        $showcaseB = Showcase::factory()->custom()->create(['tenant_id' => $this->b->id]);

        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => $productA->categories()->attach($categoryB->id));
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => $productA->syncCategories([$categoryB->id]));
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => $categoryB->products()->attach($productA->id));
        $this->assertSqlState(self::SQLSTATE_FK_VIOLATION, fn () => $showcaseB->syncProducts([$productA->id]));

        $this->assertSame(0, DB::table('category_product')->count());
        $this->assertSame(0, DB::table('showcase_product')->count());
    }

    public function test_sync_only_detaches_links_of_the_models_own_tenant(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->a->id]);
        $c1 = Category::factory()->create(['tenant_id' => $this->a->id]);
        $c2 = Category::factory()->create(['tenant_id' => $this->a->id]);

        $product->syncCategories([$c1->id, $c2->id]);
        $product->syncCategories([$c2->id]);

        $this->assertSame([$c2->id], $product->categories()->pluck('categories.id')->all());
    }

    public function test_eager_loading_categories_still_works(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->a->id]);
        $category = Category::factory()->create(['tenant_id' => $this->a->id]);
        $product->syncCategories([$category->id]);

        $loaded = Product::query()->with('categories')->findOrFail($product->id);

        $this->assertCount(1, $loaded->categories);
    }

    // -----------------------------------------------------------------
    // Vitrine TYPE_CUSTOM nunca devolve produto de outro tenant
    // -----------------------------------------------------------------

    public function test_custom_showcase_never_returns_foreign_tenant_product_even_with_corrupted_pivot(): void
    {
        $showcaseA = Showcase::factory()->custom()->create(['tenant_id' => $this->a->id, 'max_items' => 10]);
        $mine = Product::factory()->create(['tenant_id' => $this->a->id, 'name' => 'Meu']);
        $foreign = Product::factory()->create(['tenant_id' => $this->b->id, 'name' => 'Alheio']);
        $showcaseA->syncProducts([$mine->id]);

        // Simula corrupção (ex.: FK desligada por manutenção): linha cross-tenant
        // no pivô. `session_replication_role = replica` suspende os triggers de FK
        // (exige superuser; o usuário do db_test é).
        DB::statement('SET session_replication_role = replica');
        try {
            DB::table('showcase_product')->insert([
                'tenant_id' => $this->a->id, 'showcase_id' => $showcaseA->id,
                'product_id' => $foreign->id, 'sort_order' => 1,
            ]);
        } finally {
            DB::statement('SET session_replication_role = origin');
        }

        $names = $showcaseA->fresh()->getProducts()->pluck('name')->all();

        $this->assertSame(['Meu'], $names);
    }

    // -----------------------------------------------------------------
    // Formulários: Rule::exists escopado por tenant
    // -----------------------------------------------------------------

    private function actAsAdminOfA(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->a->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');
        app(TenantContext::class)->set($this->a);
    }

    public function test_product_form_rejects_category_of_another_tenant(): void
    {
        $categoryB = Category::factory()->create(['tenant_id' => $this->b->id]);
        $this->actAsAdminOfA();

        Livewire::test(ProductForm::class, ['tenant' => $this->a])
            ->set('name', 'Bolo de cenoura')
            ->set('price', '10,00')
            ->set('category_ids', [$categoryB->id])
            ->call('save')
            ->assertHasErrors(['category_ids.0']);

        $this->assertSame(0, Product::withoutTenantScope()->count());
        $this->assertSame(0, DB::table('category_product')->count());
    }

    public function test_product_form_accepts_category_of_same_tenant_and_fills_pivot_tenant(): void
    {
        $categoryA = Category::factory()->create(['tenant_id' => $this->a->id]);
        $this->actAsAdminOfA();

        Livewire::test(ProductForm::class, ['tenant' => $this->a])
            ->set('name', 'Bolo de cenoura')
            ->set('price', '10,00')
            ->set('category_ids', [$categoryA->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->a->id, DB::table('category_product')->value('tenant_id'));
    }

    public function test_showcase_form_rejects_product_and_category_of_another_tenant(): void
    {
        $productB = Product::factory()->create(['tenant_id' => $this->b->id]);
        $categoryB = Category::factory()->create(['tenant_id' => $this->b->id]);
        $this->actAsAdminOfA();

        Livewire::test(ShowcaseForm::class, ['tenant' => $this->a])
            ->set('name', 'Minha vitrine')
            ->set('type', Showcase::TYPE_CUSTOM)
            ->set('selectedProducts', [$productB->id])
            ->call('save')
            ->assertHasErrors(['selectedProducts.0']);

        Livewire::test(ShowcaseForm::class, ['tenant' => $this->a])
            ->set('name', 'Outra vitrine')
            ->set('type', Showcase::TYPE_CATEGORY)
            ->set('category_id', $categoryB->id)
            ->call('save')
            ->assertHasErrors(['category_id']);

        $this->assertSame(0, Showcase::withoutTenantScope()->count());
        $this->assertSame(0, DB::table('showcase_product')->count());
    }

    public function test_showcase_form_accepts_same_tenant_products(): void
    {
        $productA = Product::factory()->create(['tenant_id' => $this->a->id]);
        $this->actAsAdminOfA();

        Livewire::test(ShowcaseForm::class, ['tenant' => $this->a])
            ->set('name', 'Minha vitrine')
            ->set('type', Showcase::TYPE_CUSTOM)
            ->set('selectedProducts', [$productA->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->a->id, DB::table('showcase_product')->value('tenant_id'));
    }
}

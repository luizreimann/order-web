<?php

namespace Tests\Unit\Models;

use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    public function test_showcase_has_correct_types(): void
    {
        $types = Showcase::getTypes();

        $this->assertArrayHasKey(Showcase::TYPE_ALL, $types);
        $this->assertArrayHasKey(Showcase::TYPE_FEATURED, $types);
        $this->assertArrayHasKey(Showcase::TYPE_BEST_SELLERS, $types);
        $this->assertArrayHasKey(Showcase::TYPE_RECENT, $types);
        $this->assertArrayHasKey(Showcase::TYPE_CATEGORY, $types);
        $this->assertArrayHasKey(Showcase::TYPE_CUSTOM, $types);
        $this->assertCount(6, $types);
    }

    public function test_showcase_generates_slug_automatically(): void
    {
        $showcase = Showcase::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Meus Destaques',
            'type' => Showcase::TYPE_FEATURED,
        ]);

        $this->assertEquals('meus-destaques', $showcase->slug);
    }

    public function test_scope_for_tenant_filters_correctly(): void
    {
        $otherTenant = Tenant::factory()->create();

        Showcase::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
        Showcase::factory()->count(2)->create(['tenant_id' => $otherTenant->id]);

        $showcases = Showcase::query()->forTenant($this->tenant->id)->get();

        $this->assertCount(3, $showcases);
    }

    public function test_scope_active_filters_correctly(): void
    {
        Showcase::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        Showcase::factory()->count(1)->create(['tenant_id' => $this->tenant->id, 'is_active' => false]);

        $activeShowcases = Showcase::query()->forTenant($this->tenant->id)->active()->get();

        $this->assertCount(2, $activeShowcases);
    }

    public function test_get_products_type_all_returns_all_active_products(): void
    {
        Product::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        Product::factory()->count(1)->create(['tenant_id' => $this->tenant->id, 'is_active' => false]);

        $showcase = Showcase::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Showcase::TYPE_ALL,
        ]);

        $products = $showcase->getProducts();

        $this->assertCount(3, $products);
    }

    public function test_get_products_type_featured_returns_only_featured(): void
    {
        Product::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'is_featured' => true]);
        Product::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'is_featured' => false]);

        $showcase = Showcase::factory()->featured()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $products = $showcase->getProducts();

        $this->assertCount(2, $products);
    }

    public function test_get_products_type_recent_returns_newest_first(): void
    {
        $oldProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Product',
            'created_at' => now()->subDays(10),
        ]);

        $newProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'New Product',
            'created_at' => now(),
        ]);

        $showcase = Showcase::factory()->recent()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $products = $showcase->getProducts();

        $this->assertEquals('New Product', $products->first()->name);
        $this->assertEquals('Old Product', $products->last()->name);
    }

    public function test_get_products_type_category_returns_products_from_category(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCategory = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $productsInCategory = Product::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);
        $productsInCategory->each(fn ($p) => $p->categories()->attach($category->id));

        $productsInOther = Product::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
        $productsInOther->each(fn ($p) => $p->categories()->attach($otherCategory->id));

        $showcase = Showcase::factory()->category($category)->create([
            'tenant_id' => $this->tenant->id,
            'name' => $category->name,
        ]);

        $products = $showcase->getProducts();

        $this->assertCount(2, $products);
    }

    public function test_get_products_type_custom_returns_selected_products(): void
    {
        $allProducts = Product::factory()->count(5)->create(['tenant_id' => $this->tenant->id]);
        $selectedProducts = $allProducts->take(2);

        $showcase = Showcase::factory()->custom()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $showcase->syncProducts($selectedProducts->pluck('id')->toArray());

        $products = $showcase->getProducts();

        $this->assertCount(2, $products);
    }

    public function test_type_all_respects_max_items(): void
    {
        Product::factory()->count(10)->create(['tenant_id' => $this->tenant->id]);

        $showcase = Showcase::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Showcase::TYPE_ALL,
            'max_items' => 5,
        ]);

        // D5: o campo `max_items` do formulario tem efeito tambem em TYPE_ALL.
        $this->assertCount(5, $showcase->getProducts());
    }

    public function test_type_all_caps_max_items_at_the_safety_ceiling(): void
    {
        $this->insertProducts(Showcase::ALL_TYPE_CEILING + 5);

        $showcase = Showcase::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Showcase::TYPE_ALL,
            'max_items' => 500,
        ]);

        $this->assertSame(100, Showcase::ALL_TYPE_CEILING);
        $this->assertCount(100, $showcase->getProducts());
    }

    public function test_type_all_without_valid_max_items_falls_back_to_the_ceiling(): void
    {
        $this->insertProducts(Showcase::ALL_TYPE_CEILING + 5);

        // A coluna e NOT NULL; o model em memoria, porem, pode ter nulo/0/negativo
        // (ex.: instancia nao persistida). Nenhum desses casos pode virar `limit(0)`.
        foreach ([null, 0, -3] as $invalid) {
            $showcase = Showcase::factory()->make([
                'tenant_id' => $this->tenant->id,
                'type' => Showcase::TYPE_ALL,
                'max_items' => $invalid,
            ]);

            $this->assertCount(100, $showcase->getProducts(), 'max_items='.var_export($invalid, true));
        }
    }

    /**
     * Insercao em massa (uma query) para nao criar 100+ models um a um.
     */
    private function insertProducts(int $count): void
    {
        $rows = [];
        $now = now();
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'name' => 'Produto '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'slug' => 'produto-'.$i,
                'price_cents' => 1000,
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('products')->insert($rows);
    }

    public function test_get_products_respects_max_items_for_other_types(): void
    {
        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'is_featured' => true,
        ]);

        $showcase = Showcase::factory()->featured()->create([
            'tenant_id' => $this->tenant->id,
            'max_items' => 5,
        ]);

        $products = $showcase->getProducts();

        $this->assertCount(5, $products);
    }

    public function test_sync_products_maintains_order(): void
    {
        $products = Product::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
        $orderedIds = $products->pluck('id')->reverse()->toArray();

        $showcase = Showcase::factory()->custom()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $showcase->syncProducts($orderedIds);

        $syncedProducts = $showcase->products()->get();

        $this->assertEquals($orderedIds[0], $syncedProducts->first()->id);
        $this->assertEquals($orderedIds[2], $syncedProducts->last()->id);
    }

    public function test_showcase_belongs_to_tenant(): void
    {
        $showcase = Showcase::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertInstanceOf(Tenant::class, $showcase->tenant);
        $this->assertEquals($this->tenant->id, $showcase->tenant->id);
    }

    public function test_showcase_belongs_to_category(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        $showcase = Showcase::factory()->category($category)->create([
            'tenant_id' => $this->tenant->id,
            'name' => $category->name,
        ]);

        $this->assertInstanceOf(Category::class, $showcase->category);
        $this->assertEquals($category->id, $showcase->category->id);
    }

    public function test_max_showcases_per_tenant_constant(): void
    {
        $this->assertEquals(10, Showcase::MAX_SHOWCASES_PER_TENANT);
    }
}

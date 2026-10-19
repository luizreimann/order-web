<?php

namespace Tests\Feature\Database;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os pivôs category_product e showcase_product ganharam tenant_id (E2.2). Um
 * `scopeForTenant` com `where('tenant_id', ...)` não qualificado fica ambíguo
 * quando a consulta passa pela relação belongsToMany (o JOIN traz duas colunas
 * tenant_id) e o PostgreSQL responde 42702. Só aparece em PostgreSQL e só pela
 * relação, então nenhum teste unitário do scope pegava.
 */
class ForTenantScopeAmbiguityTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_tenant_through_a_belongs_to_many_relation_is_not_ambiguous(): void
    {
        $tenant = Tenant::factory()->create();
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $product->syncCategories([$category->id]);

        $ids = $category->products()->forTenant($tenant->id)->pluck('products.id')->all();

        $this->assertSame([$product->id], $ids);
    }

    public function test_for_tenant_through_every_pivot_relation_is_not_ambiguous(): void
    {
        $tenant = Tenant::factory()->create();
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $showcase = Showcase::factory()->create(['tenant_id' => $tenant->id]);
        $product->syncCategories([$category->id]);
        $showcase->syncProducts([$product->id]);

        $this->assertCount(1, $product->categories()->forTenant($tenant->id)->get());
        $this->assertCount(1, $showcase->products()->forTenant($tenant->id)->get());
        $this->assertCount(1, $category->products()->forTenant($tenant->id)->get());
    }

    public function test_category_edit_page_loads_for_a_category_with_products(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'loja-x', 'is_active' => true]);
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bolo Teste']);
        $product->syncCategories([$category->id]);

        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');

        $this->get("/loja-x/admin/categorias/{$category->id}/editar")
            ->assertOk()
            ->assertSee('Bolo Teste');
    }

    public function test_every_tenant_scope_qualifies_its_column(): void
    {
        foreach ([Product::class, Category::class, Customer::class, Showcase::class] as $model) {
            $sql = $model::query()->forTenant('x')->toSql();
            $table = (new $model)->getTable();

            $this->assertStringContainsString("\"{$table}\".\"tenant_id\"", $sql, $model.' usa tenant_id sem qualificar');
        }
    }
}

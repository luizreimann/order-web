<?php

namespace Tests\Feature\Database;

use App\Enums\Role;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Showcases\Form as ShowcaseForm;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Database\Support\InteractsWithSchema;
use Tests\TestCase;

/**
 * E2.5 (DB-005) — soft deletes em products, categories, customers, showcases.
 *
 * `orders` NÃO usa soft delete: pedido é documento comercial/financeiro e não
 * pode ser "apagado" — o ciclo de vida é o status (`cancelled`), e as FKs
 * (`payments.order_id` RESTRICT) impedem remoção física com pagamento.
 */
class SoftDeletesTest extends TestCase
{
    use InteractsWithSchema, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true]);
    }

    public function test_business_tables_have_deleted_at_and_orders_do_not(): void
    {
        foreach (['products', 'categories', 'customers', 'showcases'] as $table) {
            $this->assertSame('timestamp without time zone', $this->columnType($table, 'deleted_at'), $table);
        }

        $this->assertNull($this->columnType('orders', 'deleted_at'));
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(Order::class));

        foreach ([Product::class, Category::class, Customer::class, Showcase::class] as $model) {
            $this->assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_unique_indexes_are_partial_on_deleted_at_is_null(): void
    {
        $defs = collect(DB::select(
            "SELECT tablename, indexdef FROM pg_indexes WHERE schemaname = current_schema()
              AND tablename IN ('products','categories','showcases','customers') AND indexdef ILIKE '%UNIQUE%'"
        ))->map(fn ($r) => $r->tablename.' '.$r->indexdef);

        foreach ([
            'products' => '(tenant_id, slug)',
            'categories' => '(tenant_id, slug)',
            'showcases' => '(tenant_id, slug)',
            'customers' => '(tenant_id, phone)',
        ] as $table => $cols) {
            $this->assertTrue(
                $defs->contains(fn ($d) => str_starts_with($d, $table.' ') && str_contains($d, $cols) && str_contains($d, 'deleted_at IS NULL')),
                "unique {$table} {$cols} deveria ser parcial (WHERE deleted_at IS NULL)"
            );
        }
    }

    public function test_deleted_records_disappear_from_default_queries(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $showcase = Showcase::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([$product, $category, $customer, $showcase] as $m) {
            $m->delete();
        }

        $this->assertSame(0, Product::query()->forTenant($this->tenant->id)->count());
        $this->assertSame(0, Category::query()->forTenant($this->tenant->id)->count());
        $this->assertSame(0, Customer::query()->forTenant($this->tenant->id)->count());
        $this->assertSame(0, Showcase::query()->forTenant($this->tenant->id)->count());

        // A linha continua no banco (e é recuperável).
        $this->assertSame(1, Product::withTrashed()->forTenant($this->tenant->id)->count());
        $product->restore();
        $this->assertSame(1, Product::query()->forTenant($this->tenant->id)->count());
    }

    public function test_deleted_product_is_hidden_from_storefront_and_every_showcase_type(): void
    {
        $keep = Product::factory()->featured()->create(['tenant_id' => $this->tenant->id, 'name' => 'Fica', 'slug' => 'fica']);
        $gone = Product::factory()->featured()->create(['tenant_id' => $this->tenant->id, 'name' => 'Some', 'slug' => 'some']);
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $keep->syncCategories([$category->id]);
        $gone->syncCategories([$category->id]);

        $custom = Showcase::factory()->custom()->create(['tenant_id' => $this->tenant->id, 'max_items' => 10]);
        $custom->syncProducts([$keep->id, $gone->id]);

        $gone->delete();

        $types = [
            Showcase::factory()->make(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_ALL, 'max_items' => 10]),
            Showcase::factory()->make(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_FEATURED, 'max_items' => 10]),
            Showcase::factory()->make(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_RECENT, 'max_items' => 10]),
            Showcase::factory()->make(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_BEST_SELLERS, 'max_items' => 10]),
            Showcase::factory()->make(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_CATEGORY, 'category_id' => $category->id, 'max_items' => 10]),
            $custom,
        ];

        foreach ($types as $showcase) {
            $this->assertSame(['Fica'], $showcase->getProducts()->pluck('name')->all(), $showcase->type);
        }

        $this->get('/loja')->assertOk()->assertSee('Fica')->assertDontSee('Some');
        $this->get('/loja/p/some')->assertNotFound();
    }

    public function test_deleting_product_preserves_historical_order_items(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id]);
        $item = OrderItem::query()->create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $product->id,
            'name' => 'Bolo', 'quantity' => 1, 'unit_price_cents' => 5000, 'total_cents' => 5000,
        ]);

        $product->delete();

        $fresh = OrderItem::query()->findOrFail($item->id);
        $this->assertSame($product->id, $fresh->product_id, 'soft delete mantém o vínculo');
        $this->assertSame('Bolo', $fresh->name);
        $this->assertSame(5000, $fresh->total_cents);
        $this->assertSame(1, $order->fresh()->items()->count());

        // Mesmo a remoção física preserva o item (FK SET NULL) — snapshot intacto.
        $product->forceDelete();
        $this->assertNull(OrderItem::query()->findOrFail($item->id)->product_id);
        $this->assertSame('Bolo', OrderItem::query()->findOrFail($item->id)->name);
    }

    public function test_order_of_deleted_customer_still_resolves_the_customer(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id]);

        $customer->delete();

        $this->assertSame($customer->id, $order->fresh()->customer?->id);
    }

    public function test_slug_can_be_reused_after_delete_but_not_while_active(): void
    {
        foreach ([
            Product::class => ['price' => 1],
            Category::class => [],
            Showcase::class => ['type' => 'all'],
        ] as $model => $extra) {
            $first = $model::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bolo', 'slug' => 'bolo'] + $extra);
            $first->delete();

            $second = $model::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bolo', 'slug' => 'bolo'] + $extra);
            $this->assertSame('bolo', $second->slug, $model);

            // Com um ativo, o mesmo slug volta a ser barrado...
            $this->assertSqlState(self::SQLSTATE_UNIQUE_VIOLATION, fn () => $model::query()->create(
                ['tenant_id' => $this->tenant->id, 'name' => 'Bolo', 'slug' => 'bolo'] + $extra
            ), "{$model}: slug ativo duplicado");

            // ...e restaurar o antigo colide com o novo (também no banco).
            $this->assertSqlState(self::SQLSTATE_UNIQUE_VIOLATION, fn () => $first->restore(), "{$model}: restore colidindo");
        }
    }

    public function test_generated_slug_does_not_count_deleted_records(): void
    {
        $first = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Doces']);
        $first->delete();

        $second = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Doces']);

        $this->assertSame('doces', $second->slug);
    }

    public function test_customer_phone_can_be_reused_after_delete_but_not_while_active(): void
    {
        $old = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '11999990000']);
        $old->delete();

        $new = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '11999990000']);
        $this->assertNotSame($old->id, $new->id);

        $this->assertSqlState(self::SQLSTATE_UNIQUE_VIOLATION, fn () => Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'phone' => '11999990000',
        ]));
    }

    // -----------------------------------------------------------------
    // Formulários admin: validação de unique/exists ignora deletados
    // -----------------------------------------------------------------

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_category_form_accepts_slug_of_a_deleted_category(): void
    {
        Category::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Antiga', 'slug' => 'bolos'])->delete();
        $this->actAsAdmin();

        Livewire::test(CategoryForm::class, ['tenant' => $this->tenant])
            ->set('name', 'Bolos')
            ->set('slug', 'bolos')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Category::query()->where('slug', 'bolos')->count());
    }

    public function test_showcase_form_rejects_deleted_product_and_deleted_category(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $product->delete();
        $category->delete();
        $this->actAsAdmin();

        Livewire::test(ShowcaseForm::class, ['tenant' => $this->tenant])
            ->set('name', 'V1')
            ->set('type', Showcase::TYPE_CUSTOM)
            ->set('selectedProducts', [$product->id])
            ->call('save')
            ->assertHasErrors(['selectedProducts.0']);

        Livewire::test(ShowcaseForm::class, ['tenant' => $this->tenant])
            ->set('name', 'V2')
            ->set('type', Showcase::TYPE_CATEGORY)
            ->set('category_id', $category->id)
            ->call('save')
            ->assertHasErrors(['category_id']);
    }

    public function test_admin_delete_actions_soft_delete(): void
    {
        $this->actAsAdmin();
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        Livewire::test(\App\Livewire\Admin\Products\Index::class, ['tenant' => $this->tenant])
            ->call('delete', $product->id);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}

<?php

namespace Tests\Feature\Tenancy;

use App\Models\Category;
use App\Models\Concerns\TenantScoped;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\TenantCounter;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * E1.1 — o isolamento entre tenants é opt-out (falha fechando).
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    /**
     * Models de negócio que devem carregar o global scope de tenant.
     *
     * @var list<class-string<Model&TenantScoped>>
     */
    private const SCOPED_MODELS = [
        Category::class,
        Customer::class,
        CustomerAddress::class,
        Order::class,
        OrderItem::class,
        Payment::class,
        Product::class,
        Showcase::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);

        // Toda a massa é criada FORA de contexto de tenant, com tenant_id
        // explícito — exatamente como fazem os seeders e as factories.
        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

        $this->seedFor($this->tenantA);
        $this->seedFor($this->tenantB);
    }

    /**
     * Cria exatamente 1 registro de cada model escopado para o tenant.
     */
    private function seedFor(Tenant $tenant): void
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $product->categories()->attach($category->id);

        Showcase::factory()->create(['tenant_id' => $tenant->id]);

        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        CustomerAddress::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'label' => 'Casa',
            'line1' => 'Rua das Flores, 100',
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
        ]);

        OrderItem::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'total_cents' => 1000,
        ]);

        Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * Ids de tenant distintos VISÍVEIS pela query padrão do model.
     *
     * @param  class-string<Model&TenantScoped>  $model
     * @return array<int, mixed>
     */
    private function visibleTenantIds(string $model = Product::class): array
    {
        return $model::query()->distinct()->pluck('tenant_id')->sort()->values()->all();
    }

    // ------------------------------------------------------------------
    // 1. isolamento por model
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{class-string<Model&TenantScoped>}>
     */
    public static function scopedModelProvider(): iterable
    {
        foreach (self::SCOPED_MODELS as $class) {
            yield class_basename($class) => [$class];
        }
    }

    /**
     * @param  class-string<Model&TenantScoped>  $model
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopedModelProvider')]
    public function test_queries_are_restricted_to_the_active_tenant(string $model): void
    {
        // Sem contexto, os dois tenants estão visíveis (2 registros).
        $this->assertSame(2, $model::query()->count(), $model.': massa de teste inválida');

        $this->context->set($this->tenantA);

        $this->assertSame(1, $model::query()->count(), $model.': count() não foi escopado');
        $this->assertSame(1, $model::all()->count(), $model.': all() não foi escopado');
        $this->assertSame(
            [$this->tenantA->id],
            $this->visibleTenantIds($model),
            $model.': vazou registro de outro tenant'
        );

        $this->context->set($this->tenantB);

        $this->assertSame(1, $model::query()->count());
        $this->assertSame(
            [$this->tenantB->id],
            $this->visibleTenantIds($model)
        );
    }

    /**
     * Um `find()` pelo id de registro de OUTRO tenant não deve encontrar nada.
     */
    public function test_find_by_id_does_not_cross_tenants(): void
    {
        $productOfB = Product::query()->where('tenant_id', $this->tenantB->id)->firstOrFail();

        $this->context->set($this->tenantA);

        $this->assertNull(Product::query()->find($productOfB->id));
        $this->assertNotNull(Product::withoutTenantScope()->find($productOfB->id));
    }

    // ------------------------------------------------------------------
    // 2. auto-fill de tenant_id
    // ------------------------------------------------------------------

    public function test_create_without_tenant_id_fills_from_context(): void
    {
        $this->context->set($this->tenantA);

        $product = Product::create(['name' => 'Bolo de cenoura', 'price' => 42.50]);

        $this->assertSame($this->tenantA->id, $product->tenant_id);
        $this->assertSame($this->tenantA->id, $product->fresh()?->tenant_id);
    }

    public function test_auto_fill_runs_before_order_number_generation(): void
    {
        // Prova que o listener de `creating` da trait é registrado antes do
        // listener de `Order::booted()` (que depende de tenant_id para
        // incrementar o TenantCounter).
        $this->context->set($this->tenantA);

        $order = Order::create([
            'customer_name' => 'Fulana',
            'total_cents' => 1000,
        ]);

        $this->assertSame($this->tenantA->id, $order->tenant_id);
        $this->assertGreaterThan(0, (int) $order->number);
    }

    public function test_explicit_tenant_id_is_never_overwritten_by_context(): void
    {
        $this->context->set($this->tenantA);

        $product = Product::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Produto do B',
            'price' => 10,
        ]);

        $this->assertSame($this->tenantB->id, $product->tenant_id);
    }

    public function test_create_without_context_does_not_invent_a_tenant(): void
    {
        // Sem contexto não há auto-fill. Como `categories.tenant_id` é NOT NULL,
        // o banco recusa o insert — o registro NÃO é silenciosamente atribuído
        // a um tenant qualquer.
        $category = new Category(['name' => 'Sem tenant']);

        try {
            $category->save();
            $this->fail('O insert deveria falhar: tenant_id é NOT NULL.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tenant_id', $e->getMessage());
        }

        $this->assertNull($category->getAttribute('tenant_id'));
    }

    // ------------------------------------------------------------------
    // 3. opt-out explícito
    // ------------------------------------------------------------------

    /**
     * @param  class-string<Model&TenantScoped>  $model
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopedModelProvider')]
    public function test_without_tenant_scope_sees_every_tenant(string $model): void
    {
        $this->context->set($this->tenantA);

        /** @var \Illuminate\Database\Eloquent\Builder<Model> $query */
        $query = $model::withoutTenantScope();

        $this->assertSame(2, $query->count(), $model.': opt-out não removeu o scope');
    }

    public function test_without_tenant_scope_keeps_other_scopes_intact(): void
    {
        $this->context->set($this->tenantA);

        Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'is_active' => false,
        ]);

        // `active()` continua valendo; só o filtro de tenant saiu.
        $this->assertSame(2, Product::withoutTenantScope()->active()->count());
        $this->assertSame(3, Product::withoutTenantScope()->count());
    }

    // ------------------------------------------------------------------
    // 4. contexto de plataforma
    // ------------------------------------------------------------------

    public function test_platform_context_sees_across_tenants(): void
    {
        $this->context->set($this->tenantA);
        $this->assertSame(1, Product::query()->count());

        $this->context->usePlatformScope();

        $this->assertTrue($this->context->isPlatform());
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(2, Order::query()->count());
        $this->assertCount(
            2,
            $this->visibleTenantIds(),
            'contexto de plataforma deveria enxergar os dois tenants'
        );
    }

    public function test_platform_context_does_not_auto_fill_tenant_id(): void
    {
        $this->context->usePlatformScope();

        $category = new Category(['name' => 'Global']);

        try {
            $category->save();
            $this->fail('O insert deveria falhar: tenant_id é NOT NULL.');
        } catch (QueryException) {
            // esperado
        }

        $this->assertNull(
            $category->getAttribute('tenant_id'),
            'o contexto de plataforma não deve atribuir tenant a um registro'
        );
    }

    // ------------------------------------------------------------------
    // 5. impersonation via runFor()
    // ------------------------------------------------------------------

    public function test_run_for_restricts_to_the_assumed_tenant_and_restores_after(): void
    {
        $this->context->set($this->tenantA);

        $seen = $this->context->runFor(
            $this->tenantB,
            fn () => $this->visibleTenantIds()
        );

        $this->assertSame([$this->tenantB->id], $seen);

        // contexto anterior restaurado
        $this->assertSame($this->tenantA->id, $this->context->id());
        $this->assertSame([$this->tenantA->id], $this->visibleTenantIds());
    }

    public function test_run_for_restores_context_when_callback_throws(): void
    {
        $this->context->set($this->tenantA);

        try {
            $this->context->runFor($this->tenantB, function (): void {
                // confirma que dentro da closure o scope já mudou
                $this->assertSame([$this->tenantB->id], $this->visibleTenantIds());

                throw new RuntimeException('falha durante impersonation');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame($this->tenantA->id, $this->context->id());
        $this->assertSame(
            [$this->tenantA->id],
            $this->visibleTenantIds(),
            'o scope do tenant anterior não foi restaurado após a exceção'
        );
    }

    public function test_run_for_platform_from_a_tenant_request(): void
    {
        $this->context->set($this->tenantA);

        $count = $this->context->runForPlatform(fn () => Order::query()->count());

        $this->assertSame(2, $count);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_run_for_auto_fills_the_assumed_tenant(): void
    {
        $this->context->set($this->tenantA);

        $product = $this->context->runFor(
            $this->tenantB,
            fn () => Product::create(['name' => 'Criado sob impersonation', 'price' => 5])
        );

        $this->assertSame($this->tenantB->id, $product->tenant_id);
    }

    // ------------------------------------------------------------------
    // 6. comportamento SEM contexto (documentado: não filtra)
    // ------------------------------------------------------------------

    /**
     * Decisão E1.1: a ausência de contexto NÃO é erro e NÃO filtra.
     *
     * Precisa ser assim porque é o estado de: webhook de pagamento (rota fora
     * de `/{tenant:slug}`), seeders, comandos artisan, jobs em fila e do
     * futuro painel de plataforma. Lançar exceção aqui quebraria todos eles.
     *
     * @param  class-string<Model&TenantScoped>  $model
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopedModelProvider')]
    public function test_without_context_no_filter_is_applied_and_nothing_throws(string $model): void
    {
        $this->assertFalse($this->context->hasTenant());
        $this->assertFalse($this->context->isPlatform());

        $this->assertSame(2, $model::query()->count());
    }

    public function test_forgetting_the_context_widens_the_query_again(): void
    {
        $this->context->set($this->tenantA);
        $this->assertSame(1, Product::query()->count());

        $this->context->forget();
        $this->assertSame(2, Product::query()->count());
    }

    // ------------------------------------------------------------------
    // 7. relações, pivôs e models fora do scope
    // ------------------------------------------------------------------

    public function test_relations_inherit_the_scope(): void
    {
        $this->context->set($this->tenantA);

        $order = Order::query()->firstOrFail();

        $this->assertCount(1, $order->items);
        $this->assertCount(1, $order->payments);
        $this->assertNotNull($order->customer);
        $this->assertSame($this->tenantA->id, $order->tenant->id);
    }

    public function test_pivot_relations_without_tenant_id_still_work(): void
    {
        // `category_product` e `showcase_product` não têm tenant_id (E2.2).
        // O scope precisa qualificar a coluna com a tabela do model para não
        // quebrar o join.
        $this->context->set($this->tenantA);

        $product = Product::query()->firstOrFail();
        $category = Category::query()->firstOrFail();

        $this->assertCount(1, $product->categories);
        $this->assertTrue($product->categories->contains('id', $category->id));
        $this->assertCount(1, $category->products);

        $showcase = Showcase::query()->firstOrFail();
        $showcase->syncProducts([$product->id]);

        $this->assertCount(1, $showcase->products()->get());
    }

    public function test_showcase_get_products_stays_tenant_correct(): void
    {
        $showcaseOfB = Showcase::withoutTenantScope()
            ->where('tenant_id', $this->tenantB->id)
            ->firstOrFail();

        // Sem contexto (ex.: comando artisan) só o forTenant() interno filtra.
        $products = $showcaseOfB->getProducts();

        $this->assertCount(1, $products);
        $this->assertSame($this->tenantB->id, $products->first()?->tenant_id);

        // Em contexto de plataforma o resultado é o mesmo.
        $this->context->usePlatformScope();
        $this->assertCount(1, $showcaseOfB->getProducts());

        // E dentro do contexto do próprio tenant também.
        $this->context->set($this->tenantB);
        $this->assertCount(1, $showcaseOfB->getProducts());
    }

    public function test_tenant_model_is_never_scoped(): void
    {
        $this->context->set($this->tenantA);

        $this->assertSame(2, Tenant::query()->count());
    }

    /**
     * `users.tenant_id` é nullable porque `platform_admin` não pertence a
     * tenant nenhum (decisão D2). Aplicar o global scope aqui esconderia o
     * admin de plataforma e o auto-fill atribuiria um tenant a ele.
     */
    public function test_user_model_is_never_scoped(): void
    {
        User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'tenant_admin',
        ]);
        $platformAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'platform_admin',
        ]);

        $this->context->set($this->tenantA);

        $this->assertSame(2, User::query()->count());
        $this->assertSame($platformAdmin->id, User::query()->find($platformAdmin->id)->id);
        $this->assertNull(
            User::query()->whereKey($platformAdmin->id)->value('tenant_id'),
            'platform_admin não pode ganhar tenant_id'
        );
    }

    /**
     * `TenantCounter` continua fora do scope: a escrita passa por
     * `insertOrIgnore` (bypassa o model) e a leitura/update usa a chave
     * composta completa. Um global scope aqui criaria um `where tenant_id = X`
     * redundante — e ERRADO quando o contador incrementado é de outro tenant
     * que não o do contexto (ex.: seeder, job, webhook).
     */
    public function test_tenant_counter_is_not_scoped_and_still_isolates(): void
    {
        $this->context->set($this->tenantA);

        // Cria pedido do tenant B enquanto o contexto é o tenant A — o
        // contador de B precisa avançar, não o de A.
        $before = TenantCounter::queryFor($this->tenantB->id, 'orders')->value('value');

        Order::factory()->create(['tenant_id' => $this->tenantB->id]);

        $after = TenantCounter::queryFor($this->tenantB->id, 'orders')->value('value');

        $this->assertSame((int) $before + 1, (int) $after);
        $this->assertSame(1, (int) TenantCounter::queryFor($this->tenantA->id, 'orders')->value('value'));
    }
}

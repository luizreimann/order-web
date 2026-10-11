<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Database\Support\InteractsWithSchema;
use Tests\TestCase;

/**
 * E2.4 (DB-004, DB-007) — slug NOT NULL com geração garantida; showcases.meta jsonb.
 *
 * Escolha: slug `NOT NULL` (e não índice parcial `WHERE slug IS NOT NULL`):
 * a unique `(tenant_id, slug)` passa a valer para toda linha, e os models
 * garantem a geração (ver trait GeneratesUniqueSlug) — sem esse segundo
 * passo um nome repetido ("Bolo" 2x) estouraria a unique no banco.
 */
class SlugIntegrityTest extends TestCase
{
    use InteractsWithSchema, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function sluggedTables(): array
    {
        return [
            'products' => ['products', ['name' => 'Bolo', 'price_cents' => 100]],
            'categories' => ['categories', ['name' => 'Bolos']],
            'showcases' => ['showcases', ['name' => 'Vitrine', 'type' => 'all']],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $tenantId, ?string $slug, array $extra): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sluggedTables')]
    public function test_null_slug_is_rejected_by_the_database(string $table, array $extra): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSqlState(
            self::SQLSTATE_NOT_NULL_VIOLATION,
            fn () => DB::table($table)->insert($this->row($tenant->id, null, $extra)),
            "{$table}.slug aceitou NULL"
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sluggedTables')]
    public function test_duplicate_slug_in_same_tenant_is_rejected_but_allowed_across_tenants(string $table, array $extra): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        DB::table($table)->insert($this->row($a->id, 'mesmo-slug', $extra));
        DB::table($table)->insert($this->row($b->id, 'mesmo-slug', $extra));

        $this->assertSqlState(
            self::SQLSTATE_UNIQUE_VIOLATION,
            fn () => DB::table($table)->insert($this->row($a->id, 'mesmo-slug', $extra)),
            "{$table}: slug duplicado no mesmo tenant"
        );
    }

    public function test_models_generate_unique_slugs_for_repeated_names(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        foreach ([Product::class => ['price' => 1], Category::class => [], Showcase::class => ['type' => 'all']] as $model => $extra) {
            $s1 = $model::query()->create(['tenant_id' => $a->id, 'name' => 'Bolo Especial'] + $extra);
            $s2 = $model::query()->create(['tenant_id' => $a->id, 'name' => 'Bolo Especial'] + $extra);
            $s3 = $model::query()->create(['tenant_id' => $a->id, 'name' => 'Bolo Especial'] + $extra);
            $other = $model::query()->create(['tenant_id' => $b->id, 'name' => 'Bolo Especial'] + $extra);

            $this->assertSame('bolo-especial', $s1->slug, $model);
            $this->assertSame('bolo-especial-2', $s2->slug, $model);
            $this->assertSame('bolo-especial-3', $s3->slug, $model);
            $this->assertSame('bolo-especial', $other->slug, "{$model}: outro tenant reaproveita o slug");
        }
    }

    public function test_model_generates_slug_even_when_name_has_no_sluggable_characters(): void
    {
        $tenant = Tenant::factory()->create();

        $p1 = Product::query()->create(['tenant_id' => $tenant->id, 'name' => '!!!', 'price' => 1]);
        $p2 = Product::query()->create(['tenant_id' => $tenant->id, 'name' => '???', 'price' => 1]);

        $this->assertNotSame('', $p1->slug);
        $this->assertNotSame('', $p2->slug);
        $this->assertNotSame($p1->slug, $p2->slug);
    }

    public function test_explicit_duplicate_slug_still_fails_loudly(): void
    {
        $tenant = Tenant::factory()->create();
        Category::query()->create(['tenant_id' => $tenant->id, 'name' => 'A', 'slug' => 'x']);

        $this->assertSqlState(self::SQLSTATE_UNIQUE_VIOLATION, fn () => Category::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'x',
        ]));
    }

    public function test_showcases_meta_is_jsonb_and_supports_jsonb_operators(): void
    {
        $this->assertSame('jsonb', $this->columnType('showcases', 'meta'));

        $tenant = Tenant::factory()->create();
        Showcase::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Com tema', 'slug' => 'tema', 'meta' => ['theme' => ['color' => 'red'], 'tags' => ['natal']]]);
        Showcase::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sem tema', 'slug' => 'sem', 'meta' => ['theme' => ['color' => 'blue']]]);

        $this->assertSame(
            ['Com tema'],
            Showcase::query()->where('meta->theme->color', 'red')->pluck('name')->all()
        );

        // `@>` só existe para jsonb (json puro: "operator does not exist: json @> jsonb").
        $this->assertSame(
            ['Com tema'],
            Showcase::query()->whereRaw('meta @> ?::jsonb', [json_encode(['tags' => ['natal']])])->pluck('name')->all()
        );
    }
}

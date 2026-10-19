<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Livewire\Admin\Categories\Form;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (módulo Categorias) — `save` inválido pelo endpoint real (`POST /livewire/update`)
 * devolve o controle com `is-invalid` + `aria-invalid` e o `invalid-feedback` como irmão
 * posterior (o que o CSS do Bootstrap exige).
 *
 * Usa a tela de criação: o GET da edição de categoria está quebrado hoje (ver
 * `test_sortable_list_markup_is_preserved`).
 */
class CategoryFormValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /** @param array<string, mixed> $updates */
    private function save(array $updates): TestResponse
    {
        $page = $this->get('/tenant-a/admin/categorias/nova')->assertOk();

        return $this->callLivewire($this->snapshotFor($page->getContent(), 'admin.categories.form'), 'save', [], $updates);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'name vazio' => [['name' => ''], 'name'],
            'name curto demais' => [['name' => 'a'], 'name'],
            'slug fora do formato' => [['name' => 'Bolos', 'slug' => 'Slug Inválido!'], 'slug'],
            'sort_order negativo' => [['name' => 'Bolos', 'sort_order' => -3], 'sort_order'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('invalidInputs')]
    public function test_invalid_save_marks_the_field_and_shows_the_message(array $updates, string $id): void
    {
        $html = $this->html($this->save($updates));

        $control = $this->first($html, "//*[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "campo [{$id}] sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'));

        $feedback = $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent), "campo [{$id}] sem mensagem");
    }

    public function test_only_the_invalid_field_is_marked_and_the_label_is_bound(): void
    {
        $html = $this->html($this->save(['name' => '']));

        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame(1, $this->countNodes($html, "//label[@for='name']"));
    }

    public function test_every_invalid_field_is_marked_at_once(): void
    {
        $html = $this->html($this->save(['name' => '', 'slug' => 'Inválido!', 'sort_order' => -1]));

        foreach (['name', 'slug', 'sort_order'] as $id) {
            $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='{$id}']")), $id);
        }
        $this->assertSame(3, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_valid_save_has_no_invalid_markup_and_persists(): void
    {
        $html = $this->html($this->save(['name' => 'Bolos']));

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
        $this->assertSame(1, Category::query()->where('tenant_id', $this->tenant->id)->where('name', 'Bolos')->count());
    }

    public function test_invalid_save_does_not_persist_anything(): void
    {
        $this->save(['name' => '']);

        $this->assertSame(0, Category::query()->where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * Reordenação (SortableJS): container, ids e `data-*` que o script inline usa. O GET real
     * da edição de categoria falha hoje (`Form::loadProducts` -> `Product::scopeForTenant` com
     * `tenant_id` ambíguo no join do pivô), então o componente é montado com `loadProducts`
     * substituído, só para exercitar o markup.
     */
    public function test_sortable_list_markup_is_preserved(): void
    {
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id]);

        // `Livewire::test` pula o middleware: um GET real antes estabelece o contexto do tenant.
        $this->get('/tenant-a/admin/categorias')->assertOk();

        $stub = new class extends Form
        {
            public function loadProducts(): void
            {
                $this->products = [
                    ['id' => 'p-1', 'name' => 'Bolo de cenoura', 'sort_order' => 0, 'is_active' => true],
                    ['id' => 'p-2', 'name' => 'Bolo de fubá', 'sort_order' => 1, 'is_active' => false],
                ];
            }
        };

        $html = Livewire::test($stub::class, ['tenant' => $this->tenant, 'categoryId' => $category->id])->html();

        $this->assertSame(1, $this->countNodes($html, "//div[@id='productsList'][contains(@class,'list-group')]"));
        $this->assertSame(2, $this->countNodes($html, "//div[@id='productsList']/div[@data-product-id][contains(@class,'list-group-item')][contains(@class,'cursor-move')]"));
        $this->assertSame(['p-1', 'p-2'], array_map(
            fn ($n) => $n->getAttribute('data-product-id'),
            iterator_to_array($this->xpath($html)->query("//div[@id='productsList']/div[@data-product-id]")),
        ));
        $this->assertSame(2, $this->countNodes($html, "//div[@id='productsList']//i[contains(@class,'fa-grip-vertical')]"));
        $this->assertSame(1, $this->countNodes($html, "//span[contains(@class,'text-bg-success')][normalize-space()='Ativo']"));
        $this->assertSame(1, $this->countNodes($html, "//span[contains(@class,'text-bg-secondary')][normalize-space()='Inativo']"));
    }
}

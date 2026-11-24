<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (piloto Produtos) — prova do defeito funcional corrigido por `<x-form-field>`:
 * antes, `@error` mostrava a mensagem mas o campo NÃO recebia `is-invalid` (80 de 84).
 *
 * Tudo pelo endpoint HTTP real (`POST /livewire/update`): `save` com dado inválido devolve
 * HTML com o controle `is-invalid` E o `invalid-feedback` com a mensagem, como IRMÃO
 * POSTERIOR do controle (é o que o CSS do Bootstrap exige para exibir o feedback).
 */
class ProductFormValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        Category::factory()->create(['tenant_id' => $tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /** @param array<string, mixed> $updates */
    private function save(array $updates): TestResponse
    {
        $page = $this->get("/tenant-a/admin/produtos/{$this->product->id}/editar")->assertOk();

        return $this->callLivewire($this->snapshotFor($page->getContent(), 'admin.products.form'), 'save', [], $updates);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidInputs(): array
    {
        return [
            'name vazio' => [['name' => ''], 'name', 'name'],
            'sku longo demais' => [['sku' => str_repeat('a', 81)], 'sku', 'sku'],
            'price vazio' => [['price' => ''], 'price', 'price'],
            'sort_order negativo' => [['sort_order' => -5], 'sort_order', 'sort_order'],
            'min_days_notice acima do máximo' => [['min_days_notice' => 999], 'min_days_notice', 'min_days_notice'],
            'stock_quantity obrigatório com estoque' => [['track_inventory' => true, 'stock_quantity' => null], 'stock_quantity', 'stock_quantity'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('invalidInputs')]
    public function test_invalid_save_marks_the_field_and_shows_the_message(array $updates, string $field, string $id): void
    {
        $html = $this->html($this->save($updates));

        $control = $this->first($html, "//*[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "campo [{$field}] sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'));

        $feedback = $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent), "campo [{$field}] sem mensagem");
    }

    public function test_the_feedback_message_is_the_validation_message(): void
    {
        $html = $this->html($this->save(['name' => '']));

        $message = trim($this->first($html, "//*[@id='name']/following-sibling::div[contains(@class,'invalid-feedback')]")->textContent);

        $this->assertSame(trans('validation.required', ['attribute' => 'name']), $message);
    }

    public function test_only_the_invalid_field_is_marked(): void
    {
        $html = $this->html($this->save(['name' => '']));

        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_every_invalid_field_is_marked_at_once(): void
    {
        $html = $this->html($this->save([
            'name' => '', 'price' => '', 'sort_order' => -1, 'min_days_notice' => 999,
        ]));

        foreach (['name', 'price', 'sort_order', 'min_days_notice'] as $id) {
            $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='{$id}']")), $id);
        }
        $this->assertSame(4, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_invalid_category_selection_shows_group_feedback_visible_without_a_sibling_control(): void
    {
        $html = $this->html($this->save(['category_ids' => ['not-a-uuid']]));

        $feedback = $this->first($html, '//div[contains(@class,"invalid-feedback")]');
        $this->assertContains('d-block', $this->classes($feedback));
        $this->assertNotSame('', trim($feedback->textContent));
        // os checkboxes individuais não são marcados (o erro é do grupo)
        $this->assertSame(0, $this->countNodes($html, '//input[@type="checkbox"][contains(@class,"is-invalid")]'));
    }

    public function test_stock_field_appears_only_with_track_inventory_and_keeps_its_error(): void
    {
        $off = $this->html($this->save(['track_inventory' => false, 'name' => '']));
        $this->assertSame(0, $this->countNodes($off, "//*[@id='stock_quantity']"));

        $on = $this->html($this->save(['track_inventory' => true, 'stock_quantity' => null]));
        $this->assertSame(1, $this->countNodes($on, "//*[@id='stock_quantity']"));
    }

    public function test_valid_save_has_no_invalid_markup(): void
    {
        $html = $this->html($this->save(['name' => 'Brigadeiro']));

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
        $this->assertSame('Brigadeiro', Product::query()->findOrFail($this->product->id)->name);
    }

    public function test_invalid_save_does_not_persist_anything(): void
    {
        $before = Product::query()->findOrFail($this->product->id)->name;

        $this->save(['name' => '']);

        $this->assertSame($before, Product::query()->findOrFail($this->product->id)->name);
    }

    public function test_the_form_does_not_offer_requires_scheduling(): void
    {
        $edit = $this->get("/tenant-a/admin/produtos/{$this->product->id}/editar")->assertOk()->getContent();
        $create = $this->get('/tenant-a/admin/produtos/novo')->assertOk()->getContent();
        $rerender = $this->html($this->save(['name' => '']));

        foreach ([$edit, $create, $rerender] as $html) {
            $this->assertStringNotContainsString('Exigir data/hora no pedido', $html);
            $this->assertStringNotContainsString('requires_scheduling', $html);
        }
    }

    public function test_save_keeps_the_stored_requires_scheduling_value(): void
    {
        $this->product->update(['requires_scheduling' => true, 'name' => 'Original', 'min_days_notice' => 2]);

        $response = $this->save(['name' => 'Brigadeiro', 'price' => '12,50', 'min_days_notice' => 4]);

        $this->assertNotNull($response->json('components.0.effects.redirect'));
        $fresh = Product::query()->findOrFail($this->product->id);
        $this->assertTrue($fresh->requires_scheduling);
        $this->assertSame('Brigadeiro', $fresh->name);
        $this->assertSame(1250, (int) $fresh->price_cents);
        $this->assertSame(4, $fresh->min_days_notice);

        $this->product->update(['requires_scheduling' => false]);

        $this->save(['name' => 'Beijinho'])->assertOk();

        $fresh = Product::query()->findOrFail($this->product->id);
        $this->assertFalse($fresh->requires_scheduling);
        $this->assertSame('Beijinho', $fresh->name);
    }

    public function test_min_days_notice_blank_is_null_and_zero_is_zero(): void
    {
        $this->product->update(['min_days_notice' => 5, 'requires_scheduling' => true]);

        $this->save(['min_days_notice' => ''])->assertOk();

        $fresh = Product::query()->findOrFail($this->product->id);
        $this->assertNull($fresh->min_days_notice);
        $this->assertTrue($fresh->requires_scheduling);

        $this->save(['min_days_notice' => '0'])->assertOk();

        $fresh = Product::query()->findOrFail($this->product->id);
        $this->assertSame(0, $fresh->min_days_notice);
        $this->assertTrue($fresh->requires_scheduling);

        $this->product->update(['min_days_notice' => null]);

        $this->save(['min_days_notice' => 0])->assertOk();

        $this->assertSame(0, Product::query()->findOrFail($this->product->id)->min_days_notice);
    }

    public function test_new_product_keeps_the_database_default_for_requires_scheduling(): void
    {
        $page = $this->get('/tenant-a/admin/produtos/novo')->assertOk();

        $this->callLivewire(
            $this->snapshotFor($page->getContent(), 'admin.products.form'),
            'save',
            [],
            ['name' => 'Quindim', 'price' => '8,00', 'min_days_notice' => ''],
        )->assertOk();

        $created = Product::query()->where('name', 'Quindim')->firstOrFail();
        $this->assertFalse($created->requires_scheduling);
        $this->assertNull($created->min_days_notice);
    }
}

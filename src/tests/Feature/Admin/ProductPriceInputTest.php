<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Entrada de preço no formulário de produto, pelo endpoint real do Livewire. Defeito corrigido:
 * o formulário EXIBE "1.250,00" mas o mutator lia "1.250" como 1,25 (salvar sem tocar gravava R$ 1,25)
 * e "abc" virava R$ 0,00 sem erro.
 */
class ProductPriceInputTest extends TestCase
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
    private function save(Product $product, array $updates = []): TestResponse
    {
        $page = $this->get("/tenant-a/admin/produtos/{$product->id}/editar")->assertOk();

        return $this->callLivewire($this->snapshotFor($page->getContent(), 'admin.products.form'), 'save', [], $updates);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    public function test_saving_a_1250_product_without_touching_the_price_keeps_it(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id, 'price_cents' => 125000, 'compare_at_cents' => 150000,
        ]);

        $page = $this->get("/tenant-a/admin/produtos/{$product->id}/editar")->assertOk();
        // O valor do `wire:model` vem do snapshot do componente (não de um atributo `value`).
        $data = json_decode($this->snapshotFor($page->getContent(), 'admin.products.form'), true)['data'];
        $this->assertSame('1.250,00', $data['price']);
        $this->assertSame('1.500,00', $data['compare_at']);

        $response = $this->save($product);
        $response->assertOk();

        $this->assertNotNull($response->json('components.0.effects.redirect'), 'o save deveria redirecionar para a lista');
        $product->refresh();
        $this->assertSame(125000, (int) $product->price_cents);
        $this->assertSame(150000, (int) $product->compare_at_cents);
    }

    /** @return array<string, array{int}> */
    public static function cents(): array
    {
        return array_map(fn (int $c) => [$c], [
            '0,99' => 99, '1,00' => 100, '19,99' => 1999, '1.000,00' => 100000, '1.250,00' => 125000,
            '999.999,99' => 99999999, '1.234.567,89' => 123456789,
        ]);
    }

    #[DataProvider('cents')]
    public function test_display_then_save_is_idempotent(int $cents): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id, 'price_cents' => $cents, 'compare_at_cents' => $cents,
        ]);

        $this->save($product)->assertOk();
        $this->save($product->refresh())->assertOk();

        $product->refresh();
        $this->assertSame($cents, (int) $product->price_cents);
        $this->assertSame($cents, (int) $product->compare_at_cents);
    }

    /** @return array<string, array{string}> */
    public static function invalidPrices(): array
    {
        return ['letras' => ['abc'], 'negativo' => ['-5,00'], 'ambíguo sem vírgula' => ['1.250'], 'decimais demais' => ['1,999'], 'duas vírgulas' => ['1,2,3']];
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_price_is_rejected_and_nothing_is_written(string $input): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 125000, 'name' => 'Original']);

        $html = $this->html($this->save($product, ['price' => $input, 'name' => 'Alterado']));

        $control = $this->first($html, "//*[@id='price']");
        $this->assertContains('is-invalid', $this->classes($control));
        $feedback = $this->first($html, "//*[@id='price']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent));

        $product->refresh();
        $this->assertSame(125000, (int) $product->price_cents);
        $this->assertSame('Original', $product->name);
    }

    public function test_invalid_compare_at_is_rejected_and_nothing_is_written(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 1000, 'compare_at_cents' => null]);

        $html = $this->html($this->save($product, ['compare_at' => 'abc']));

        $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='compare_at']")));
        $this->assertNull($product->refresh()->compare_at_cents);
    }

    public function test_empty_compare_at_is_allowed_and_clears_it(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 1000, 'compare_at_cents' => 2000]);

        $response = $this->save($product, ['compare_at' => '']);

        $this->assertNotNull($response->json('components.0.effects.redirect'));
        $this->assertNull($product->refresh()->compare_at_cents);
    }

    public function test_pt_br_input_with_thousands_is_saved_correctly(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 100]);

        $this->save($product, ['price' => 'R$ 2.499,90', 'compare_at' => '3.000,00'])->assertOk();

        $product->refresh();
        $this->assertSame(249990, (int) $product->price_cents);
        $this->assertSame(300000, (int) $product->compare_at_cents);
    }

    public function test_the_form_displays_what_money_format_produces(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 99999999]);

        $page = $this->get("/tenant-a/admin/produtos/{$product->id}/editar")->assertOk();
        $data = json_decode($this->snapshotFor($page->getContent(), 'admin.products.form'), true)['data'];

        $this->assertSame(Money::format(99999999), $data['price']);
    }
}

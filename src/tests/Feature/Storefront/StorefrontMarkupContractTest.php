<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (storefront) — contratos de marcação que o JS e a UI de estoque dependem e que a
 * migração para componentes Blade não pode quebrar:
 *
 *  - carrossel: `resources/js/carousel.js` procura `.showcase-carousel` e o Splide exige
 *    `.splide__track > .splide__list > .splide__slide`;
 *  - checkout: o JS do Mercado Pago lê `getElementById` (nome/telefone/e-mail, Brick, PIX,
 *    card) e `input[name="payment_method_choice"]:checked`;
 *  - estoque: "Esgotado" desabilita botão e quantidade; o limite aparece na UI.
 */
class StorefrontMarkupContractTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true, 'settings' => [
            'payment' => [
                'methods' => [
                    ['id' => 'mp_pix', 'enabled' => true, 'label' => 'PIX (MP)', 'provider' => 'mercadopago'],
                    ['id' => 'cash_on_delivery', 'enabled' => true, 'label' => 'Dinheiro'],
                ],
                'mercadopago' => ['public_key' => 'TEST-pk', 'access_token' => 'tok'],
            ],
        ]]);
    }

    /** XPath para atributo `wire:*` (o prefixo `wire:` não é um namespace XML válido). */
    private function wire(string $attr, string $value): string
    {
        return "@*[name()='{$attr}' and .=".(str_contains($value, "'") ? '"'.$value.'"' : "'{$value}'").']';
    }

    private function product(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge(['tenant_id' => $this->tenant->id, 'meta' => []], $attrs));
    }

    public function test_carousel_showcase_keeps_the_splide_structure_the_js_needs(): void
    {
        $this->product(['name' => 'Bolo']);
        $this->product(['name' => 'Torta']);
        $carousel = Showcase::factory()->create(['tenant_id' => $this->tenant->id, 'type' => Showcase::TYPE_ALL, 'display_mode' => 'carousel']);
        Showcase::factory()->create(['tenant_id' => $this->tenant->id, 'slug' => 'lista', 'type' => Showcase::TYPE_ALL, 'display_mode' => 'list', 'sort_order' => 2]);

        $html = $this->get('/loja')->assertOk()->getContent();

        $root = $this->first($html, "//div[@id='showcase-{$carousel->id}']");
        $this->assertContains('splide', $this->classes($root));
        $this->assertContains('showcase-carousel', $this->classes($root));
        $this->assertSame(1, $this->countNodes($html, "//div[@id='showcase-{$carousel->id}']/div[contains(@class,'splide__track')]/ul[contains(@class,'splide__list')]"));
        $this->assertSame(2, $this->countNodes($html, "//ul[contains(@class,'splide__list')]/li[contains(@class,'splide__slide')]"));
        // O card continua dentro do slide, com a imagem/corpo do produto.
        $this->assertSame(2, $this->countNodes($html, "//li[contains(@class,'splide__slide')]//div[contains(@class,'card') and contains(@class,'h-100')]/div[contains(@class,'card-body')]"));
        // O modo lista não vira carrossel.
        $this->assertSame(1, $this->countNodes($html, "//div[contains(@class,'showcase-carousel')]"));
        $this->assertSame(1, $this->countNodes($html, "//div[contains(@class,'list-group')]"));
    }

    public function test_out_of_stock_product_disables_the_button_and_the_quantity(): void
    {
        $this->product(['slug' => 'esgotado'])->update(['track_inventory' => true, 'stock_quantity' => 0]);

        $html = $this->get('/loja/p/esgotado')->assertOk()->getContent();

        $button = $this->first($html, '//button['.$this->wire('wire:click', 'addToCart').']');
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertStringContainsString('Esgotado', $button->textContent);
        $this->assertTrue($this->first($html, '//input['.$this->wire('wire:model.live', 'qty').']')->hasAttribute('disabled'));
        $this->assertSame(1, $this->countNodes($html, "//span[contains(@class,'badge') and contains(@class,'text-bg-secondary')]"));
    }

    public function test_limited_stock_shows_the_available_units_and_the_limit_error(): void
    {
        $this->product(['slug' => 'limitado'])->update(['track_inventory' => true, 'stock_quantity' => 3]);

        $page = $this->get('/loja/p/limitado')->assertOk()->getContent();

        $this->assertStringContainsString('3 unidade(s) disponível(is).', $page);
        $this->assertFalse($this->first($page, '//button['.$this->wire('wire:click', 'addToCart').']')->hasAttribute('disabled'));

        $html = (string) $this->callLivewire($this->snapshotFor($page, 'tenant.product-show'), 'addToCart', [], ['qty' => 9])->json('components.0.effects.html');
        $this->assertStringContainsString('Só é possível adicionar mais 3 unidade(s)', $html);
        $this->assertSame(1, $this->countNodes($html, "//div[contains(@class,'text-warning-emphasis')]"));
    }

    public function test_cart_keeps_quantity_controls_and_the_over_stock_warning(): void
    {
        $product = $this->product(['name' => 'Limitado'])->fresh();
        $product->update(['track_inventory' => true, 'stock_quantity' => 1]);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 3]]);

        $html = $this->get('/loja/carrinho')->assertOk()->getContent();

        $this->assertSame(1, $this->countNodes($html, '//button['.$this->wire('wire:click', "decrement('{$product->id}')").']'));
        $this->assertSame(1, $this->countNodes($html, '//button['.$this->wire('wire:click', "increment('{$product->id}')").']'));
        $this->assertSame(1, $this->countNodes($html, '//button['.$this->wire('wire:click', "remove('{$product->id}')").']'));
        $this->assertSame(1, $this->countNodes($html, '//button['.$this->wire('wire:click', 'clear').']'));
        $this->assertSame(1, $this->countNodes($html, "//div[contains(@class,'btn-group')]/button[@disabled]"));
        $this->assertStringContainsString('Disponível: 1 — ajuste a quantidade.', $html);
    }

    public function test_checkout_keeps_every_id_and_selector_the_mercado_pago_script_uses(): void
    {
        $product = $this->product(['price_cents' => 1000]);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 1]]);

        $html = $this->get('/loja/checkout')->assertOk()->getContent();

        foreach (['customerNameInput', 'customerPhoneInput', 'customerEmailInput', 'paymentBrick_container', 'mpPaymentCard', 'mpPixInfo'] as $id) {
            $this->assertSame(1, preg_match_all('/\sid="'.preg_quote($id, '/').'"/', $html), "#{$id} deve existir 1 vez");
        }

        $this->assertSame(1, $this->countNodes($html, "//div[@id='mpPaymentCard']//div[@*[name()='wire:ignore']]/div[@id='paymentBrick_container']"));
        $this->assertSame(1, $this->countNodes($html, "//div[@id='mpPaymentCard']//div[@id='mpPixInfo' and @style]"));
        $this->assertSame(2, $this->countNodes($html, "//input[@type='radio' and @name='payment_method_choice' and ".$this->wire('wire:model.live', 'payment_method').']'));
        $this->assertSame(1, $this->countNodes($html, "//input[@id='customerNameInput' and ".$this->wire('wire:model.defer', 'customer_name').']'));
        $this->assertSame(1, $this->countNodes($html, "//input[@id='customerPhoneInput' and ".$this->wire('wire:model.defer', 'customer_phone').']'));
        $this->assertSame(1, $this->countNodes($html, "//input[@id='customerEmailInput' and ".$this->wire('wire:model.defer', 'customer_email').']'));
        // MP é o primeiro método: o card do Brick fica visível (sem `display:none`).
        $this->assertStringNotContainsString('display:none', $this->first($html, "//div[@id='mpPaymentCard']")->getAttribute('style'));
    }

    public function test_tenant_theme_still_reaches_the_shared_components(): void
    {
        // White label: o tema atua em variáveis/classes do Bootstrap; os componentes nunca fixam cor.
        $this->tenant->forceFill(['primary_color' => '#e91e63', 'secondary_color' => '#009688'])->save();
        $product = $this->product(['slug' => 'bolo', 'price_cents' => 1000]);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 1]]);

        foreach (['/loja/p/bolo', '/loja/carrinho', '/loja/checkout'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            $this->assertStringContainsString('--tenant-primary: #e91e63;', $html, $uri);
            $this->assertStringContainsString('--tenant-secondary: #009688;', $html, $uri);
            $this->assertStringContainsString('.btn-primary {', $html, $uri);
            $this->assertMatchesRegularExpression('/class="btn btn-(outline-)?(primary|secondary)\b/', $html, $uri);
            // Nada de cor fixa vinda do componente (nem `bg-primary`, nem `text-primary`, nem hex em style).
            $body = substr($html, (int) strpos($html, '<body'));
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\b(bg|text)-primary\b/', $body, $uri);
            $this->assertDoesNotMatchRegularExpression('/style="[^"]*#[0-9a-f]{3,6}/i', $body, $uri);
        }
    }
}

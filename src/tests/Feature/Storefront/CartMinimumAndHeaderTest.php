<?php

namespace Tests\Feature\Storefront;

use App\Livewire\Tenant\Cart;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use App\Support\TenantHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Carrinho: abaixo do pedido mínimo mostra o aviso e bloqueia o botão do checkout. Cabeçalho da
 * loja: só fala de taxa e tempo de entrega quando a loja realmente entrega.
 */
class CartMinimumAndHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $settings): Tenant
    {
        return Tenant::factory()->create(['slug' => 'loja-c', 'is_active' => true, 'settings' => $settings]);
    }

    private function cart(Tenant $tenant, int $qty)
    {
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 2500]);
        app(CartService::class)->add($tenant, $product->id, $qty);

        return Livewire::test(Cart::class, ['tenant' => $tenant]);
    }

    public function test_below_the_minimum_the_notice_shows_and_the_checkout_button_is_disabled(): void
    {
        $this->withoutVite();
        $tenant = $this->tenant(['delivery' => ['minimum_order_cents' => 7000]]);

        $html = $this->cart($tenant, 2)
            ->assertSee('Pedido mínimo de R$ 70,00. Faltam R$ 20,00.')
            ->html();

        $this->assertDoesNotMatchRegularExpression('#<a[^>]*href="[^"]*/loja-c/checkout"[^>]*>(?:<!--.*?-->|\s)*Ir para o checkout#s', $html, 'sem link para o checkout');
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>(?:<!--.*?-->|\s)*Ir para o checkout/s', $html);
    }

    public function test_adding_an_item_reaches_the_minimum_and_releases_the_button(): void
    {
        $this->withoutVite();
        $tenant = $this->tenant(['delivery' => ['minimum_order_cents' => 7000]]);

        $component = $this->cart($tenant, 2);
        $product = Product::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $component->call('increment', $product->id)
            ->assertDontSee('Faltam R$')
            ->assertSeeHtml('/loja-c/checkout');
    }

    public function test_without_a_minimum_nothing_changes(): void
    {
        $this->withoutVite();

        $this->cart($this->tenant([]), 1)->assertDontSee('Pedido mínimo')->assertSeeHtml('/loja-c/checkout');
    }

    public function test_the_header_info_ignores_the_delivery_fee_and_time_when_the_store_does_not_deliver(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => false, 'default_fee_cents' => 700, 'estimated_time_minutes' => 45, 'minimum_order_cents' => 2000]]);

        $info = TenantHelper::getDeliveryInfo($tenant);

        $this->assertFalse($info['delivers']);
        $this->assertSame(0.0, (float) $info['fee']);
        $this->assertNull($info['estimated_time']);
        $this->assertSame(20.0, (float) $info['minimum_order'], 'o mínimo vale para retirada também');
    }

    public function test_the_header_info_shows_the_flat_fee_or_the_lowest_region_fee(): void
    {
        $flat = $this->tenant(['delivery' => ['enabled' => true, 'default_fee_cents' => 700, 'estimated_time_minutes' => 45]]);
        $this->assertSame(7.0, (float) TenantHelper::getDeliveryInfo($flat)['fee']);
        $this->assertSame(45, TenantHelper::getDeliveryInfo($flat)['estimated_time']);

        $flat->update(['settings' => ['delivery' => ['enabled' => true, 'default_fee_cents' => 700, 'regions' => [
            ['id' => 'a', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
            ['id' => 'b', 'name' => 'Alto', 'fee_cents' => 900, 'active' => true],
            ['id' => 'c', 'name' => 'Inativo', 'fee_cents' => 100, 'active' => false],
        ]]]]);

        $info = TenantHelper::getDeliveryInfo($flat->refresh());
        $this->assertSame(5.0, (float) $info['fee']);
        $this->assertTrue($info['fee_from']);
    }

    public function test_the_storefront_header_renders_a_partir_de(): void
    {
        $this->withoutVite();
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'regions' => [
            ['id' => 'a', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
            ['id' => 'b', 'name' => 'Alto', 'fee_cents' => 900, 'active' => true],
        ]]]);

        $this->get('/'.$tenant->slug)->assertOk()->assertSee('a partir de R$ 5,00');
    }

    public function test_the_header_never_starts_with_a_dangling_separator(): void
    {
        $this->withoutVite();
        // Loja só de retirada com mínimo (a tela padrão): só "Pedido mín.", sem "•" antes.
        $tenant = $this->tenant(['delivery' => ['minimum_order_cents' => 2000]]);

        $html = $this->get('/'.$tenant->slug)->assertOk()->assertSee('Pedido mín. R$ 20,00')->getContent();

        $this->assertDoesNotMatchRegularExpression('/<div class="text-muted small mt-1">\s*<span class="mx-1">/', $html);
        $this->assertStringNotContainsString('mx-1">•</span>', $html, 'um item só não precisa de separador');
    }

    public function test_the_header_separates_only_between_the_items_that_exist(): void
    {
        $this->withoutVite();
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'estimated_time_minutes' => 45, 'default_fee_cents' => 700, 'minimum_order_cents' => 2000]]);

        $html = $this->get('/'.$tenant->slug)->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'mx-1">•</span>'), 'três itens, dois separadores');
    }

    public function test_the_header_is_absent_when_there_is_nothing_to_say(): void
    {
        $this->withoutVite();
        $tenant = $this->tenant([]);

        $this->get('/'.$tenant->slug)->assertOk()->assertDontSee('Pedido mín.')->assertDontSee('min</');
    }
}

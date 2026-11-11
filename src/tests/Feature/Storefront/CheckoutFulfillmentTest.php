<?php

namespace Tests\Feature\Storefront;

use App\Enums\FulfillmentType;
use App\Livewire\Tenant\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Checkout: o cliente escolhe retirada ou entrega, informa bairro e endereço, vê a taxa e o total, e
 * não consegue enviar abaixo do pedido mínimo. Taxa e total aqui são só exibição: o pedido gravado
 * é o que o servidor recalcula (ver `OrderFulfillmentTest`).
 */
class CheckoutFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeTenant(array $fulfillment): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'loja-f',
            'is_active' => true,
            'settings' => array_replace_recursive([
                'payment' => ['methods' => [['id' => 'cash_on_delivery', 'provider' => 'manual', 'label' => 'Dinheiro', 'enabled' => true]]],
                'address' => ['city' => 'Curitiba', 'state' => 'pr'],
            ], $fulfillment),
        ]);

        $this->product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 2500, 'name' => 'Bolo']);
        app(CartService::class)->add($tenant, $this->product->id, 2);

        return $tenant;
    }

    private function deliveryTenant(array $delivery = [], array $pickup = []): void
    {
        $this->tenant = $this->makeTenant([
            'pickup' => array_replace(['enabled' => true, 'fee_cents' => 0], $pickup),
            'delivery' => array_replace([
                'enabled' => true,
                'default_fee_cents' => 700,
                'regions' => [
                    ['id' => 'r-centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                    ['id' => 'r-alto', 'name' => 'Bairro Alto', 'fee_cents' => 900, 'active' => true],
                ],
            ], $delivery),
        ]);
    }

    private function checkout(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Checkout::class, ['tenant' => $this->tenant])
            ->set('customer_name', 'Ana Souza')
            ->set('customer_email', 'ana@example.com')
            ->set('customer_phone', '11999990000')
            ->set('payment_method', 'cash_on_delivery');
    }

    private function fillAddress($component)
    {
        return $component
            ->set('address_line1', 'Rua das Flores, 120')
            ->set('address_line2', 'Apto 3')
            ->set('address_city', 'Curitiba')
            ->set('address_state', 'PR')
            ->set('address_postal_code', '80010-000');
    }

    private function orders(): int
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
    }

    public function test_a_store_without_fulfillment_settings_looks_and_behaves_as_before(): void
    {
        $this->tenant = $this->makeTenant([]);
        $component = $this->checkout();

        $component->assertSet('fulfillment_type', 'pickup')
            ->assertDontSee('Como você quer receber?')
            ->assertDontSee('deliveryFields', false)
            ->assertDontSee('Taxa de entrega')
            ->assertSeeHtml('R$ 50,00');

        $component->call('placeOrderManual')->assertHasNoErrors();

        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(FulfillmentType::Pickup, $order->fulfillment_type);
        $this->assertSame(5000, $order->total_cents);
    }

    public function test_with_only_delivery_on_there_is_no_choice_and_delivery_fields_show(): void
    {
        $this->deliveryTenant();
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings, ['pickup' => ['enabled' => false]])]);
        $this->tenant->refresh();

        $this->checkout()
            ->assertSet('fulfillment_type', 'delivery')
            ->assertSee('Entrega')
            ->assertSeeHtml('id="deliveryFields"')
            ->assertDontSeeHtml('fulfillment_type_choice');
    }

    public function test_both_on_shows_the_choice_and_prefills_city_and_state_from_the_store(): void
    {
        $this->deliveryTenant();

        $component = $this->checkout()
            ->assertSet('fulfillment_type', 'pickup')
            ->assertSeeHtml('fulfillment_type_choice')
            ->assertDontSeeHtml('id="deliveryFields"');

        $component->set('fulfillment_type', 'delivery')
            ->assertSeeHtml('id="deliveryFields"')
            ->assertSet('address_city', 'Curitiba')
            ->assertSet('address_state', 'PR');
    }

    public function test_choosing_a_region_shows_the_fee_and_the_total_includes_it(): void
    {
        $this->deliveryTenant();

        $component = $this->checkout()->set('fulfillment_type', 'delivery');

        $component->assertSee('Escolha o bairro para ver a taxa de entrega.')->assertSeeHtml('R$ 50,00');

        $component->set('region_id', 'r-alto')
            ->assertDontSee('Escolha o bairro para ver a taxa de entrega.')
            ->assertSee('Taxa de entrega')
            ->assertSee('Bairro Alto')
            ->assertSeeHtml('R$ 9,00')
            ->assertSeeHtml('id="orderTotal">R$ 59,00');
    }

    public function test_the_pickup_fee_is_shown_and_added(): void
    {
        $this->deliveryTenant(pickup: ['fee_cents' => 300]);

        $this->checkout()
            ->assertSee('Taxa de retirada')
            ->assertSeeHtml('id="orderTotal">R$ 53,00');
    }

    public function test_free_delivery_is_announced_and_zeroes_the_fee(): void
    {
        $this->deliveryTenant(['free_delivery_above_cents' => 4000]);

        $this->checkout()->set('fulfillment_type', 'delivery')->set('region_id', 'r-alto')
            ->assertSee('Entrega grátis')
            ->assertSeeHtml('id="orderTotal">R$ 50,00');
    }

    public function test_below_the_minimum_the_notice_shows_the_buttons_are_disabled_and_nothing_is_written(): void
    {
        $this->deliveryTenant(['minimum_order_cents' => 8000]);

        $component = $this->checkout()
            ->assertSee('Pedido mínimo de R$ 80,00. Faltam R$ 30,00.')
            ->assertSeeHtml('id="minimumOrderNotice"');

        $this->assertMatchesRegularExpression('/<button[^>]*wire:click="placeOrderManual"[^>]*disabled/', $component->html());

        $component->call('placeOrderManual')->assertHasErrors('cart');

        $this->assertSame(0, $this->orders());
    }

    public function test_exactly_at_the_minimum_the_order_goes_through(): void
    {
        $this->deliveryTenant(['minimum_order_cents' => 5000]);

        $this->checkout()->call('placeOrderManual')->assertHasNoErrors();

        $this->assertSame(1, $this->orders());
    }

    public function test_a_delivery_order_stores_address_region_fee_and_total_and_clears_the_cart(): void
    {
        $this->deliveryTenant();

        $component = $this->fillAddress($this->checkout()->set('fulfillment_type', 'delivery')->set('region_id', 'r-centro'));
        $component->call('placeOrderManual')->assertHasNoErrors()->assertRedirect();

        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(FulfillmentType::Delivery, $order->fulfillment_type);
        $this->assertSame(500, $order->delivery_fee_cents);
        $this->assertSame(5500, $order->total_cents);
        $this->assertSame('Rua das Flores, 120', $order->address_line1);
        $this->assertSame('Centro', data_get($order->meta, 'fulfillment.region_name'));
        $this->assertSame([], app(CartService::class)->items($this->tenant));
    }

    public function test_delivery_without_address_marks_every_missing_field_and_writes_nothing(): void
    {
        $this->deliveryTenant();

        $this->checkout()->set('fulfillment_type', 'delivery')->set('region_id', 'r-centro')
            ->set('address_city', '')->set('address_state', '')
            ->call('placeOrderManual')
            ->assertHasErrors(['address_line1', 'address_city', 'address_state']);

        $this->assertSame(0, $this->orders());
    }

    public function test_delivery_with_regions_and_no_choice_marks_the_region_field(): void
    {
        $this->deliveryTenant();

        $component = $this->fillAddress($this->checkout()->set('fulfillment_type', 'delivery'));
        $component->call('placeOrderManual')->assertHasErrors('region_id');

        $this->assertSame(0, $this->orders());
        $this->assertStringContainsString('is-invalid', $component->html());
    }

    public function test_the_flat_fee_applies_without_active_regions_and_no_region_select_shows(): void
    {
        $this->deliveryTenant(['regions' => []]);

        $component = $this->fillAddress($this->checkout()->set('fulfillment_type', 'delivery'))
            ->assertDontSeeHtml('id="regionSelect"')
            ->assertSeeHtml('id="orderTotal">R$ 57,00');

        $component->call('placeOrderManual')->assertHasNoErrors();
        $this->assertSame(700, Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole()->delivery_fee_cents);
    }

    public function test_a_forged_unknown_type_is_refused_not_trusted(): void
    {
        $this->deliveryTenant();

        $this->checkout()->set('fulfillment_type', 'drone')->call('placeOrderManual')->assertHasErrors('fulfillment_type');

        $this->assertSame(0, $this->orders());
    }

    public function test_changing_the_type_back_to_pickup_drops_the_delivery_fee(): void
    {
        $this->deliveryTenant();

        $this->checkout()->set('fulfillment_type', 'delivery')->set('region_id', 'r-alto')
            ->set('fulfillment_type', 'pickup')
            ->assertDontSee('Taxa de entrega')
            ->assertSeeHtml('id="orderTotal">R$ 50,00');
    }

    public function test_delivery_without_region_and_address_reports_everything_at_once(): void
    {
        $this->deliveryTenant();

        $this->checkout()->set('fulfillment_type', 'delivery')
            ->set('address_city', '')->set('address_state', '')
            ->call('placeOrderManual')
            ->assertHasErrors(['region_id', 'address_line1', 'address_city', 'address_state']);

        $this->assertSame(0, $this->orders());
    }

    public function test_the_estimated_time_shows_before_a_region_is_chosen(): void
    {
        $this->deliveryTenant(['estimated_time_minutes' => 45]);

        $this->checkout()->set('fulfillment_type', 'delivery')->assertSee('Entrega em cerca de 45 min.');
    }

    public function test_the_minimum_phrase_is_not_repeated_in_the_top_alert_when_the_notice_is_visible(): void
    {
        $this->deliveryTenant(['minimum_order_cents' => 8000]);

        $component = $this->checkout()->call('placeOrderManual');

        $this->assertSame(1, substr_count($component->html(), 'Pedido mínimo de R$ 80,00'));
    }
}

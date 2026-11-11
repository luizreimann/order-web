<?php

namespace Tests\Feature\Payments;

use App\Enums\FulfillmentType;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * `POST /{tenant}/process_payment` com retirada/entrega: o valor cobrado no gateway é o total que o
 * SERVIDOR calcula (subtotal + taxa). O cliente só escolhe tipo, bairro e endereço; qualquer taxa ou
 * total enviado por ele é ignorado. Recusas (mínimo, endereço, bairro) não criam pedido nem cobram.
 */
class ProcessPaymentFulfillmentTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    private const ADDRESS = ['line1' => 'Rua das Flores, 120', 'line2' => 'Apto 3', 'city' => 'Curitiba', 'state' => 'PR', 'postal_code' => '80010-000'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings, [
            'delivery' => [
                'enabled' => true,
                'default_fee_cents' => 700,
                'regions' => [
                    ['id' => 'r-centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                    ['id' => 'r-alto', 'name' => 'Bairro Alto', 'fee_cents' => 900, 'active' => true],
                ],
            ],
        ])]);
        $this->tenant->refresh();
        $this->product = $this->product($this->tenant, 2500);
    }

    private function pay(array $fulfillment, int $qty = 1, array $extra = [])
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $this->product, $qty))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => [], 'fulfillment' => $fulfillment] + $extra));
    }

    private function sentOrderBody(): array
    {
        $sent = [];
        Http::assertSent(function (Request $request) use (&$sent) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/orders')) {
                $sent = $request->data();
            }

            return true;
        });

        return $sent;
    }

    /** Cada pagamento tem o seu id de order no gateway (único no banco): o segundo pedido ganha outro. */
    private function nextGatewayOrder(string $id): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeMpOrderCreation($id);
    }

    private function orders(): int
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
    }

    public function test_the_gateway_is_charged_subtotal_plus_the_region_fee(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0001');

        $this->pay(['type' => 'delivery', 'region_id' => 'r-alto', 'address' => self::ADDRESS], qty: 2)->assertOk();

        $sent = $this->sentOrderBody();
        $this->assertSame('59.00', $sent['total_amount']);
        $this->assertSame('59.00', $sent['transactions']['payments'][0]['amount']);

        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(5900, $order->total_cents);
        $this->assertSame(FulfillmentType::Delivery, $order->fulfillment_type);
        $this->assertSame(900, $order->delivery_fee_cents);
        $this->assertSame('Rua das Flores, 120', $order->address_line1);
    }

    public function test_without_a_fulfillment_choice_the_charge_is_the_plain_subtotal_as_before(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0002');

        $this->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();

        $this->assertSame('50.00', $this->sentOrderBody()['total_amount']);
        $this->assertSame(FulfillmentType::Pickup, Order::withoutTenantScope()->sole()->fulfillment_type);
    }

    public function test_money_sent_by_the_client_is_ignored(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0003');

        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS, 'fee_cents' => 1, 'total_cents' => 1, 'delivery_fee_cents' => 0], extra: ['total' => 0.01, 'amount' => 0.01])->assertOk();

        $this->assertSame('30.00', $this->sentOrderBody()['total_amount']);
        $this->assertSame(500, Order::withoutTenantScope()->sole()->delivery_fee_cents);
    }

    public function test_missing_address_is_refused_field_by_field_before_any_order_or_charge(): void
    {
        Http::fake();

        $response = $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => ['line1' => '', 'city' => '', 'state' => 'X']])->assertStatus(422);

        $this->assertSame('invalid_fulfillment', $response->json('error'));
        $this->assertEqualsCanonicalizing(['address_line1', 'address_city', 'address_state'], array_keys($response->json('errors')));
        $this->assertSame(0, $this->orders());
        Http::assertNothingSent();
    }

    public function test_a_missing_region_is_refused_on_the_region_field(): void
    {
        Http::fake();

        $response = $this->pay(['type' => 'delivery', 'address' => self::ADDRESS])->assertStatus(422);

        $this->assertArrayHasKey('region_id', $response->json('errors'));
        $this->assertSame(0, $this->orders());
        Http::assertNothingSent();
    }

    public function test_below_the_minimum_is_refused_with_the_shortfall_and_nothing_is_created_or_charged(): void
    {
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings, ['delivery' => ['minimum_order_cents' => 8000]])]);
        Http::fake();

        $response = $this->pay(['type' => 'pickup'], qty: 2)->assertStatus(422);

        $this->assertSame('below_minimum', $response->json('error'));
        $this->assertSame(8000, $response->json('minimum_cents'));
        $this->assertSame(3000, $response->json('shortfall_cents'));
        $this->assertSame('Pedido mínimo de R$ 80,00. Faltam R$ 30,00.', $response->json('message'));
        $this->assertSame(0, $this->orders());
        Http::assertNothingSent();
    }

    public function test_a_type_the_store_turned_off_is_refused(): void
    {
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->settings, ['delivery' => ['enabled' => false]])]);
        Http::fake();

        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS])->assertStatus(422);

        $this->assertSame(0, $this->orders());
        Http::assertNothingSent();
    }

    public function test_a_cart_that_became_empty_between_the_screen_and_the_payment_is_refused_not_a_500(): void
    {
        Http::fake();
        $this->product->update(['is_active' => false]);

        $response = $this->pay(['type' => 'pickup']);

        $this->assertContains($response->getStatusCode(), [422]);
        $this->assertSame(0, $this->orders());
        Http::assertNothingSent();
    }

    public function test_a_different_region_is_a_different_checkout_not_a_replay_of_the_first_order(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0004');

        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS])->assertOk();
        $this->nextGatewayOrder('ORDFEE0004B');
        $this->pay(['type' => 'delivery', 'region_id' => 'r-alto', 'address' => self::ADDRESS])->assertOk();

        $this->assertSame(2, $this->orders(), 'outro bairro = outro total = outro pedido');
        $this->assertEqualsCanonicalizing([3000, 3400], Order::withoutTenantScope()->pluck('total_cents')->all());
    }

    public function test_the_same_choice_twice_is_still_the_same_checkout_double_click_safe(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0005');

        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS])->assertOk();
        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS])->assertOk();

        $this->assertSame(1, $this->orders());
    }

    public function test_a_different_address_for_the_same_region_is_a_different_checkout(): void
    {
        $this->fakeMpOrderCreation('ORDFEE0006');

        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS])->assertOk();
        $this->nextGatewayOrder('ORDFEE0006B');
        $this->pay(['type' => 'delivery', 'region_id' => 'r-centro', 'address' => ['line1' => 'Outra Rua, 9'] + self::ADDRESS])->assertOk();

        $this->assertSame(2, $this->orders(), 'o endereço novo não pode herdar o pedido do endereço antigo');
    }
}

<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Gateways\PaymentGatewayManager;
use App\Livewire\Tenant\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

/**
 * E3.5 — PROVA da abstração (D4): checkout ponta a ponta com um gateway que NÃO é o
 * Mercado Pago. Criação do pedido -> pagamento -> webhook confirmando, usando só
 * `PaymentGatewayInterface`, o resolver e as rotas genéricas.
 *
 * O Mercado Pago é bloqueado de ponta a ponta: o container falha o teste se alguém tentar
 * instanciar `MercadoPagoGateway` e nenhuma requisição HTTP é permitida.
 */
class FakeGatewayEndToEndTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const SECRET = 'fake-secret';

    private FakeGateway $fake;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhuma linha do MP pode rodar neste arquivo.
        $this->app->bind(MercadoPagoGateway::class, fn () => $this->fail('o fluxo do FakeGateway instanciou o MercadoPagoGateway'));
        Http::preventStrayRequests();

        $this->fake = new FakeGateway;
        app(PaymentGatewayManager::class)->extend('fake', fn () => $this->fake);

        // O tenant escolhe o gateway do método `mp_pix` (resolução por tenant/método).
        $this->tenant = Tenant::factory()->create([
            'slug' => 'fake-loja',
            'is_active' => true,
            'settings' => ['payment' => [
                'fake' => ['secret' => self::SECRET],
                'methods' => [
                    ['id' => 'mp_pix', 'provider' => 'fake', 'label' => 'PIX', 'enabled' => true],
                    ['id' => 'cash_on_delivery', 'provider' => 'manual', 'label' => 'Dinheiro', 'enabled' => true],
                ],
            ]],
        ]);
    }

    private function pay(Product $product, int $qty = 1): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $product, $qty))
            ->postJson('/fake-loja/process_payment', $this->processPaymentBody(['formData' => []]));
    }

    private function webhook(string $id, ?string $secret = self::SECRET, ?string $delivery = null): \Illuminate\Testing\TestResponse
    {
        $headers = array_filter([
            'x-fake-signature' => $secret === null ? null : FakeGateway::signature($id, $secret),
            'x-fake-delivery' => $delivery,
        ]);

        return $this->postJson('/webhooks/fake/'.$this->tenant->id, ['id' => $id], $headers);
    }

    public function test_checkout_then_webhook_confirms_the_payment_end_to_end_without_touching_mercado_pago(): void
    {
        $product = $this->product($this->tenant, 3000, stock: 5);

        // 1) pedido + pagamento
        $response = $this->pay($product, 2)->assertOk()->assertJson(['ok' => true, 'status' => 'pending']);

        $order = Order::withoutTenantScope()->sole();
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame($order->id, $response->json('order_id'));
        $this->assertSame('mp_pix', $order->payment_method);
        $this->assertSame('fake', $payment->gateway);
        $this->assertSame('fake-1', $payment->gateway_payment_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(6000, $payment->amount_cents);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame(3, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // O gateway recebeu o valor do PEDIDO e a chave de idempotência persistida.
        $this->assertCount(1, $this->fake->charges);
        $this->assertSame(6000, $this->fake->charges[0]['amount_cents']);
        $this->assertSame($payment->idempotency_key, $this->fake->charges[0]['idempotency_key']);

        // 2) o provedor aprova e avisa por webhook (rota genérica com tenant)
        $this->fake->remote['fake-1']['status'] = 'approved';

        $this->webhook('fake-1')->assertOk()->assertJson(['ok' => true, 'transitioned' => true]);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
        $this->assertSame('approved', $payment->fresh()?->status);

        // 3) reenvio: nada muda
        $this->webhook('fake-1')->assertOk()->assertJson(['ok' => true, 'transitioned' => false]);
        $this->assertSame(1, DB::table('payment_webhook_events')->where('gateway', 'fake')->count());

        Http::assertNothingSent();
    }

    public function test_webhook_with_a_bad_signature_is_rejected_before_anything_is_consulted(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->webhook('fake-qualquer', secret: 'errado')->assertStatus(401);
        $this->webhook('fake-qualquer', secret: null)->assertStatus(401);

        $this->assertSame([], array_values(array_filter($queries, fn ($s) => preg_match('/"(orders|payments|payment_webhook_events)"/', $s))));
    }

    public function test_the_tenantless_generic_route_resolves_the_fake_gateway_and_authenticates_with_the_payment_owner(): void
    {
        $product = $this->product($this->tenant);
        $this->pay($product)->assertOk();
        $this->fake->remote['fake-1']['status'] = 'approved';

        $this->postJson('/webhooks/fake', ['id' => 'fake-1'], ['x-fake-signature' => 'x'])->assertStatus(401);
        $this->postJson('/webhooks/fake', ['id' => 'fake-1'], ['x-fake-signature' => FakeGateway::signature('fake-1', self::SECRET)])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(PaymentStatus::PAID, Order::withoutTenantScope()->sole()->payment_status);
    }

    public function test_a_declined_fake_payment_cancels_the_order_and_returns_the_stock(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 4);
        $this->fake->nextStatus = 'rejected';

        $this->pay($product, 3)->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->sole()->status);
        $this->assertSame(4, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->assertSame('rejected', Payment::withoutTenantScope()->sole()->status);
    }

    public function test_an_exception_in_the_fake_gateway_is_compensated_like_any_other_gateway(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 4);
        $this->fake->throwOnCreate = new \RuntimeException('boom');

        $this->pay($product, 1)->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->sole()->status);
        $this->assertSame(4, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->assertSame('failed', Payment::withoutTenantScope()->sole()->status);
    }

    public function test_double_submit_charges_the_fake_gateway_once(): void
    {
        $product = $this->product($this->tenant);

        $first = $this->pay($product)->assertOk();
        $second = $this->pay($product)->assertOk();

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertCount(1, $this->fake->charges);
        $this->assertSame(1, Order::withoutTenantScope()->count());
    }

    public function test_the_checkout_component_offers_and_initializes_the_fake_gateway_method_without_mercado_pago_ids(): void
    {
        $component = Livewire::test(Checkout::class, ['tenant' => $this->tenant]);

        // `mp_pix` aparece porque o gateway escolhido pelo tenant (fake) está configurado.
        $this->assertSame(['mp_pix', 'cash_on_delivery'], array_column($component->get('payment_methods'), 'id'));

        $component->set('payment_method', 'mp_pix')
            ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => ($params[0]['publicKey'] ?? null) === 'fake-public-key'
                    && str_ends_with((string) $params[0]['processPaymentUrl'], '/fake-loja/process_payment'));
    }

    public function test_the_checkout_component_hides_online_methods_of_an_unconfigured_gateway(): void
    {
        $settings = $this->tenant->settings;
        $settings['payment']['fake']['secret'] = null;
        $this->tenant->update(['settings' => $settings]);

        $component = Livewire::test(Checkout::class, ['tenant' => $this->tenant->fresh()]);

        $this->assertSame(['cash_on_delivery'], array_column($component->get('payment_methods'), 'id'));
    }

    public function test_the_checkout_component_source_has_no_hardcoded_mercado_pago_method_ids(): void
    {
        $source = (string) file_get_contents(app_path('Livewire/Tenant/Checkout.php'));

        $this->assertStringNotContainsString('MP_', $source);
        $this->assertStringNotContainsString('mp_', $source);
        $this->assertStringNotContainsString('mercadopago', strtolower($source));
        $this->assertStringNotContainsString('MercadoPago', $source);
    }

    public function test_cart_is_untouched_by_the_other_test_fixtures(): void
    {
        $this->assertSame([], app(CartService::class)->items($this->tenant));
    }
}

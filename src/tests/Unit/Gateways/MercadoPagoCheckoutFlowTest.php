<?php

namespace Tests\Unit\Gateways;

use App\Enums\PaymentMethod;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * Contrato do gateway com o checkout reformulado: o `flow` que o front segue e o corpo da
 * cobrança montado por método (PIX direto; lista branca no cartão).
 */
class MercadoPagoCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private MercadoPagoGateway $gateway;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new MercadoPagoGateway;
        $this->tenant = Tenant::factory()->create(['settings' => ['payment' => ['mercadopago' => [
            'public_key' => 'TEST-public-key',
            'access_token' => EncryptionHelper::encryptIfNeeded('TEST-access-token'),
        ]]]]);
    }

    public function test_checkout_payload_flow_and_excluded_types_per_method(): void
    {
        $payload = fn (PaymentMethod $m) => $this->gateway->checkoutPayload($this->tenant, $m, 5000, 'https://loja.test/x/process_payment');

        $pix = $payload(PaymentMethod::MP_PIX);
        $this->assertSame('direct', $pix['flow']);
        $this->assertSame([], $pix['excludedTypes']);

        $credit = $payload(PaymentMethod::MP_CREDIT_CARD);
        $this->assertSame('card', $credit['flow']);
        $this->assertSame(['debit_card'], $credit['excludedTypes']);

        $debit = $payload(PaymentMethod::MP_DEBIT_CARD);
        $this->assertSame('card', $debit['flow']);
        $this->assertSame(['credit_card', 'prepaid_card'], $debit['excludedTypes']);

        // Nenhum segredo vai ao navegador: só a chave PÚBLICA.
        $this->assertSame('TEST-public-key', $debit['publicKey']);
        $this->assertStringNotContainsString('TEST-access-token', json_encode($debit));
        $this->assertSame(50.0, $debit['amount']);
    }

    public function test_a_charge_without_a_mercado_pago_method_is_refused_without_a_request(): void
    {
        // Sem `payment_method` não há "uso avulso": o corpo da order é SEMPRE montado por método.
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'total_cents' => 1000]);
        Http::fake();

        $result = $this->gateway->createPayment($this->tenant, $order, [
            'formData' => ['payment_method_id' => 'pix'],
            'customer_email' => 'fallback@example.com',
        ]);

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_the_pix_body_has_no_card_fields(): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'total_cents' => 1000]);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000000001', 'pending', $order), 201)]);

        $this->gateway->createPayment($this->tenant, $order, [
            'payment_method' => 'mp_pix', 'customer_email' => 'a@b.co',
            'formData' => ['token' => 'tok', 'issuer_id' => '1', 'installments' => 3],
        ]);

        Http::assertSent(function (Request $r) {
            $method = $r['transactions']['payments'][0]['payment_method'];

            return $method === ['id' => 'pix', 'type' => 'bank_transfer']
                && ! isset($r['token']) && ! isset($r['issuer_id']) && ! isset($r['installments']);
        });
    }

    public function test_a_card_charge_without_token_fails_before_any_request(): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'total_cents' => 1000]);
        Http::fake();

        $result = $this->gateway->createPayment($this->tenant, $order, [
            'payment_method' => 'mp_debit_card', 'customer_email' => 'a@b.co', 'formData' => ['payment_method_id' => 'debvisa'],
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('missing_card_token', $result->meta['error']);
        Http::assertNothingSent();
    }
}

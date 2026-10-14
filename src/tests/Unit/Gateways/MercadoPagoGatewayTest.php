<?php

namespace Tests\Unit\Gateways;

use App\Contracts\PaymentResult;
use App\Contracts\WebhookResult;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoGatewayTest extends TestCase
{
    use RefreshDatabase;

    private MercadoPagoGateway $gateway;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new MercadoPagoGateway;
        $this->tenant = Tenant::factory()->create([
            'settings' => [
                'payment' => [
                    'mercadopago' => [
                        'public_key' => 'TEST-public-key',
                        'access_token' => EncryptionHelper::encryptIfNeeded('TEST-access-token'),
                        'webhook_secret' => EncryptionHelper::encryptIfNeeded('test-webhook-secret'),
                    ],
                ],
            ],
        ]);
    }

    public function test_integrator_id_header_comes_from_config(): void
    {
        config(['services.mercadopago.integrator_id' => 'dev_configurado-por-env']);
        Http::fake(['api.mercadopago.com/*' => Http::response([], 200)]);

        $this->assertTrue($this->gateway->testConnection($this->tenant));

        Http::assertSent(fn ($request) => $request->hasHeader('X-Integrator-Id', 'dev_configurado-por-env'));
    }

    public function test_integrator_id_header_is_omitted_when_config_is_empty(): void
    {
        config(['services.mercadopago.integrator_id' => '']);
        Http::fake(['api.mercadopago.com/*' => Http::response([], 200)]);

        $this->assertTrue($this->gateway->testConnection($this->tenant));

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Integrator-Id'));
    }

    public function test_create_payment_returns_failure_without_credentials(): void
    {
        $tenantWithoutCreds = Tenant::factory()->create(['settings' => []]);
        $order = Order::factory()->create(['tenant_id' => $tenantWithoutCreds->id]);

        $result = $this->gateway->createPayment($tenantWithoutCreds, $order, []);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Credenciais', $result->errorMessage);
    }

    public function test_create_payment_calls_the_api_with_the_attempt_key_and_does_not_persist(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'id' => 123456789,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'payment_method_id' => 'pix',
            ], 200),
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'total_cents' => 10000,
        ]);

        $result = $this->gateway->createPayment($this->tenant, $order, [
            'formData' => ['token' => 'test-token'],
            'customer_email' => 'test@example.com',
            'idempotency_key' => 'chave-da-tentativa',
        ]);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('123456789', $result->paymentId);
        $this->assertEquals('approved', $result->status);

        // E3.4: o gateway faz SÓ a chamada externa. Quem registra o Payment é o
        // PaymentService (ANTES da chamada, com a idempotency_key, e consolidando depois);
        // aqui o que importa é que a chave persistida da tentativa chegue ao MP.
        $this->assertDatabaseCount('payments', 0);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Idempotency-Key', 'chave-da-tentativa')
            && $request['notification_url'] === url('/webhooks/mercadopago/'.$this->tenant->id));
    }

    public function test_create_payment_returns_failure_on_api_error(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response([
                'message' => 'Invalid token',
                'error' => 'bad_request',
            ], 400),
        ]);

        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'total_cents' => 10000,
        ]);

        $result = $this->gateway->createPayment($this->tenant, $order, [
            'formData' => ['token' => 'invalid-token'],
        ]);

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertEquals(400, $result->errorCode);
    }

    public function test_get_payment_returns_payment_result_on_success(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments/123456789' => Http::response([
                'id' => 123456789,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'payment_method_id' => 'pix',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'order_id' => 'test-order-id',
                ],
            ], 200),
        ]);

        $result = $this->gateway->getPayment($this->tenant, '123456789');

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('123456789', $result->paymentId);
        $this->assertEquals('approved', $result->status);
    }

    public function test_get_payment_returns_null_on_api_error(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments/999999999' => Http::response([], 404),
        ]);

        $result = $this->gateway->getPayment($this->tenant, '999999999');

        $this->assertNull($result);
    }

    public function test_handle_webhook_ignores_non_payment_type(): void
    {
        $result = $this->gateway->handleWebhook([
            'type' => 'merchant_order',
            'data' => ['id' => '123'],
        ]);

        $this->assertInstanceOf(WebhookResult::class, $result);
        $this->assertFalse($result->processed);
        $this->assertStringContainsString('não suportado', $result->errorMessage);
    }

    public function test_handle_webhook_fails_without_payment_id(): void
    {
        $result = $this->gateway->handleWebhook([
            'type' => 'payment',
        ]);

        $this->assertInstanceOf(WebhookResult::class, $result);
        $this->assertFalse($result->processed);
        $this->assertStringContainsString('payment_id ausente', $result->errorMessage);
    }

    public function test_handle_webhook_ignores_unknown_payment(): void
    {
        $result = $this->gateway->handleWebhook([
            'type' => 'payment',
            'data' => ['id' => '999999999'],
        ]);

        $this->assertInstanceOf(WebhookResult::class, $result);
        $this->assertFalse($result->processed);
        $this->assertStringContainsString('não encontrado', $result->errorMessage);
    }

    public function test_handle_webhook_updates_order_and_payment(): void
    {
        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_status' => 'unpaid',
            'meta' => [
                'mercadopago' => [
                    'payment_id' => '123456789',
                ],
            ],
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => '123456789',
            'amount_cents' => $order->total_cents,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments/123456789' => Http::response([
                'id' => 123456789,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'payment_method_id' => 'pix',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'order_id' => $order->id,
                ],
            ], 200),
        ]);

        $result = $this->gateway->handleWebhook([
            'type' => 'payment',
            'data' => ['id' => '123456789'],
        ]);

        $this->assertInstanceOf(WebhookResult::class, $result);
        $this->assertTrue($result->processed);
        $this->assertEquals($order->id, $result->orderId);
        $this->assertEquals('paid', $result->paymentStatus);

        $order->refresh();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);

        $payment->refresh();
        $this->assertEquals('approved', $payment->status);
    }

    public function test_validate_webhook_signature_returns_true_in_dev_without_secret(): void
    {
        $this->app['env'] = 'local';

        $result = $this->gateway->validateWebhookSignature('123', [], null);

        $this->assertTrue($result);
    }

    public function test_validate_webhook_signature_returns_false_without_header(): void
    {
        $result = $this->gateway->validateWebhookSignature('123', [], 'secret-key');

        $this->assertFalse($result);
    }

    public function test_validate_webhook_signature_validates_correct_signature(): void
    {
        $dataId = '123456789';
        $requestId = 'request-123';
        $ts = '1234567890';
        $secretKey = 'my-secret-key';

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expectedHash = hash_hmac('sha256', $manifest, $secretKey);

        $headers = [
            'x-signature' => "ts={$ts},v1={$expectedHash}",
            'x-request-id' => $requestId,
        ];

        $result = $this->gateway->validateWebhookSignature($dataId, $headers, $secretKey);

        $this->assertTrue($result);
    }

    public function test_validate_webhook_signature_rejects_invalid_signature(): void
    {
        $headers = [
            'x-signature' => 'ts=1234567890,v1=invalid_hash',
            'x-request-id' => 'request-123',
        ];

        $result = $this->gateway->validateWebhookSignature('123', $headers, 'secret-key');

        $this->assertFalse($result);
    }

    public function test_test_connection_returns_true_on_success(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payment_methods' => Http::response([
                ['id' => 'pix', 'name' => 'PIX'],
            ], 200),
        ]);

        $result = $this->gateway->testConnection($this->tenant);

        $this->assertTrue($result);
    }

    public function test_test_connection_returns_false_on_failure(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payment_methods' => Http::response([], 401),
        ]);

        $result = $this->gateway->testConnection($this->tenant);

        $this->assertFalse($result);
    }

    public function test_test_connection_returns_false_without_credentials(): void
    {
        $tenantWithoutCreds = Tenant::factory()->create(['settings' => []]);

        $result = $this->gateway->testConnection($tenantWithoutCreds);

        $this->assertFalse($result);
    }

    // ------------------------------------------------- E3.5: contrato da interface

    public function test_key_is_the_provider_key_used_by_the_resolver_and_the_webhook_route(): void
    {
        $this->assertSame('mercadopago', $this->gateway->key());
    }

    public function test_is_configured_needs_public_key_and_a_decryptable_access_token(): void
    {
        $this->assertTrue($this->gateway->isConfigured($this->tenant));
        $this->assertFalse($this->gateway->isConfigured(Tenant::factory()->create(['settings' => []])));
        $this->assertFalse($this->gateway->isConfigured(Tenant::factory()->create(['settings' => [
            'payment' => ['mercadopago' => ['public_key' => 'pk', 'access_token' => null]],
        ]])));
        $this->assertFalse($this->gateway->isConfigured(Tenant::factory()->create(['settings' => [
            'payment' => ['mercadopago' => ['access_token' => EncryptionHelper::encryptIfNeeded('tok')]],
        ]])));
    }

    public function test_checkout_payload_restricts_the_brick_by_method_and_never_leaks_the_token(): void
    {
        $url = 'https://loja.test/x/process_payment';

        $pix = $this->gateway->checkoutPayload($this->tenant, PaymentMethod::MP_PIX, 12345, $url);
        $this->assertSame(['creditCard' => null, 'debitCard' => null, 'bankTransfer' => ['pix']], $pix['allowed']);
        $this->assertSame(123.45, $pix['amount']);
        $this->assertSame('TEST-public-key', $pix['publicKey']);
        $this->assertSame($url, $pix['processPaymentUrl']);

        $credit = $this->gateway->checkoutPayload($this->tenant, PaymentMethod::MP_CREDIT_CARD, 100, $url);
        $this->assertSame(['creditCard' => 'all', 'debitCard' => null, 'bankTransfer' => null], $credit['allowed']);

        $debit = $this->gateway->checkoutPayload($this->tenant, PaymentMethod::MP_DEBIT_CARD, 100, $url);
        $this->assertSame(['creditCard' => null, 'debitCard' => 'all', 'bankTransfer' => null], $debit['allowed']);

        $this->assertStringNotContainsString('TEST-access-token', json_encode($pix));
    }

    public function test_parse_webhook_extracts_the_payment_and_the_delivery_id_without_io(): void
    {
        $event = $this->gateway->parseWebhook(['type' => 'payment', 'data' => ['id' => 123]], ['x-request-id' => 'req-9']);
        $this->assertSame('123', $event->paymentId);
        $this->assertSame('req-9', $event->requestId);

        $legacy = $this->gateway->parseWebhook(['topic' => 'payment', 'id' => '456'], []);
        $this->assertSame('456', $legacy->paymentId);
        $this->assertNull($legacy->requestId);

        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'merchant_order', 'data' => ['id' => 1]], [])->ignoredReason);
        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'payment'], [])->invalidReason);
        $this->assertFalse($this->gateway->parseWebhook(['type' => 'payment', 'data' => 'x'], [])->isActionable());
    }

    public function test_validate_webhook_uses_the_secret_of_the_given_tenant(): void
    {
        $headers = function (string $secret, string $dataId = '321') {
            $hash = hash_hmac('sha256', "id:{$dataId};request-id:r1;ts:100;", $secret);

            return ['x-signature' => "ts=100,v1={$hash}", 'x-request-id' => 'r1'];
        };
        $payload = ['type' => 'payment', 'data' => ['id' => '321']];

        $this->assertTrue($this->gateway->validateWebhook($this->tenant, $payload, $headers('test-webhook-secret')));
        $this->assertFalse($this->gateway->validateWebhook($this->tenant, $payload, $headers('outro-secret')));
        // A assinatura cobre o id: trocar o id do payload invalida.
        $this->assertFalse($this->gateway->validateWebhook($this->tenant, ['data' => ['id' => '999']], $headers('test-webhook-secret')));
    }
}

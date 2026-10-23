<?php

namespace Tests\Unit\Gateways;

use App\Contracts\PaymentCancellation;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MercadoPagoOrders;
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

    public function test_the_integrator_id_default_is_empty_so_the_header_is_not_sent_unless_configured(): void
    {
        // O default antigo era o `dev_...` de OUTRA pessoa: venderia por conta dela.
        $services = require config_path('services.php');
        $this->assertSame('', $services['mercadopago']['integrator_id'] ?? null);
        $this->assertStringNotContainsString('dev_b527aa24b4f611ebbb980242ac130004', (string) file_get_contents(config_path('services.php')));
    }

    private int $orderSequence = 0;

    private function order(int $totalCents = 10000, ?int $number = null): Order
    {
        return Order::factory()->create(['tenant_id' => $this->tenant->id, 'total_cents' => $totalCents, 'number' => $number ?? 1000 + ++$this->orderSequence]);
    }

    /** @param  array<string, mixed>  $extra */
    private function pixData(array $extra = []): array
    {
        return array_merge(['payment_method' => 'mp_pix', 'customer_email' => 'test@example.com', 'customer_name' => 'Maria da Silva', 'idempotency_key' => 'chave-da-tentativa'], $extra);
    }

    /** @param  array<string, mixed>  $extra */
    private function cardData(array $extra = []): array
    {
        return array_merge([
            'payment_method' => 'mp_credit_card', 'customer_email' => 'test@example.com', 'customer_name' => 'Maria da Silva', 'idempotency_key' => 'chave-do-cartao',
            'formData' => ['token' => 'tok_1', 'payment_method_id' => 'master', 'payer' => ['identification' => ['type' => 'CPF', 'number' => '12345678909']]],
        ], $extra);
    }

    /** @return array<string, mixed> o corpo do `POST /v1/orders` */
    private function postedBody(): array
    {
        $body = [];
        Http::assertSent(function (Request $r) use (&$body) {
            if ($r->method() === 'POST') {
                $body = $r->data();
            }

            return true;
        });

        return $body;
    }

    public function test_create_payment_posts_the_order_with_the_attempt_key_and_does_not_persist(): void
    {
        $order = $this->order(10000);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000123', 'pending', $order), 201)]);

        $result = $this->gateway->createPayment($this->tenant, $order, $this->pixData());

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('ORDTEST000123', $result->paymentId, 'o id é o da ORDER');
        $this->assertSame('pending', $result->status, 'action_required/waiting_transfer => pending');
        $this->assertSame('waiting_transfer', $result->statusDetail);
        $this->assertSame('PAYTEST000123', $result->meta['transaction_id']);
        $this->assertSame('ORDTEST000123', $result->meta['payment_id']);
        $this->assertSame($order->id, $result->meta['metadata']['order_id']);
        $this->assertSame(MercadoPagoOrders::QR_CODE, $result->meta['point_of_interaction']['transaction_data']['qr_code']);
        $this->assertSame('action_required', $result->meta['gateway_details']['order_status']);

        // E3.4: o gateway faz SÓ a chamada externa. Quem registra o Payment é o PaymentService.
        $this->assertDatabaseCount('payments', 0);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/orders'
            && $r->hasHeader('X-Idempotency-Key', 'chave-da-tentativa')
            && $r->hasHeader('Authorization', 'Bearer TEST-access-token'));
    }

    public function test_the_pix_order_body_follows_the_orders_api(): void
    {
        $order = $this->order(2599, 77);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000124', 'pending', $order), 201)]);

        $this->gateway->createPayment($this->tenant, $order, $this->pixData());

        $this->assertSame([
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => '25.99',
            'external_reference' => $order->id,
            'description' => 'Pedido #77',
            'payer' => ['email' => 'test@example.com', 'first_name' => 'Maria', 'last_name' => 'da Silva'],
            'transactions' => ['payments' => [[
                'amount' => '25.99',
                'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer'],
                'expiration_time' => 'PT30M',
            ]]],
        ], $this->postedBody());
    }

    public function test_amounts_are_strings_with_two_decimals(): void
    {
        foreach ([[100, '1.00'], [5, '0.05'], [1000000, '10000.00'], [12345, '123.45']] as [$cents, $expected]) {
            Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000125', 'pending', null), 201)]);

            $this->gateway->createPayment($this->tenant, $this->order($cents), $this->pixData());

            $body = $this->postedBody();
            $this->assertSame($expected, $body['total_amount']);
            $this->assertSame($expected, $body['transactions']['payments'][0]['amount']);
        }
    }

    public function test_the_card_order_body_follows_the_orders_api(): void
    {
        $order = $this->order(5000);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000126', 'approved', $order), 201)]);

        $result = $this->gateway->createPayment($this->tenant, $order, $this->cardData());

        $this->assertSame('approved', $result->status);
        $this->assertSame([
            'amount' => '50.00',
            'payment_method' => ['id' => 'master', 'type' => 'credit_card', 'token' => 'tok_1', 'installments' => 1],
        ], $this->postedBody()['transactions']['payments'][0]);
        $this->assertSame(
            ['email' => 'test@example.com', 'first_name' => 'Maria', 'last_name' => 'da Silva', 'identification' => ['type' => 'CPF', 'number' => '12345678909']],
            $this->postedBody()['payer']
        );
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function customerNames(): array
    {
        return [
            'nome composto' => ['Maria da Silva Souza', ['first_name' => 'Maria', 'last_name' => 'da Silva Souza']],
            'duas palavras' => ['João Pedro', ['first_name' => 'João', 'last_name' => 'Pedro']],
            'uma palavra: só first_name, sem inventar sobrenome' => ['Maria', ['first_name' => 'Maria']],
            'espaços extras e quebra de linha' => ['  Ana 
  Lima  ', ['first_name' => 'Ana', 'last_name' => 'Lima']],
            'vazio: nenhum nome' => ['', []],
            'só espaços: nenhum nome' => ['   ', []],
            'acentos' => ['José da Conceição', ['first_name' => 'José', 'last_name' => 'da Conceição']],
        ];
    }

    /** @param  array<string, string>  $expected */
    #[DataProvider('customerNames')]
    public function test_payer_names_come_from_the_checkout_name_without_inventing_data(string $name, array $expected): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000127', 'pending', null), 201)]);

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['customer_name' => $name]));

        $this->assertSame(['email' => 'test@example.com'] + $expected, $this->postedBody()['payer']);
    }

    public function test_a_very_long_name_is_truncated_not_rejected(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000128', 'pending', null), 201)]);

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['customer_name' => str_repeat('A', 200).' '.str_repeat('B', 200)]));

        $payer = $this->postedBody()['payer'];
        $this->assertSame(60, mb_strlen($payer['first_name']));
        $this->assertSame(60, mb_strlen($payer['last_name']));
    }

    public function test_the_external_reference_is_the_order_id_within_the_api_limits(): void
    {
        $order = $this->order();
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000129', 'pending', $order), 201)]);

        $this->gateway->createPayment($this->tenant, $order, $this->pixData());

        $ref = $this->postedBody()['external_reference'];
        $this->assertSame($order->id, $ref);
        // Sandbox: máx. 64 caracteres, só letras, dígitos, hífen e sublinhado.
        $this->assertLessThanOrEqual(64, strlen($ref));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $ref);
    }

    public function test_create_payment_requires_a_mercado_pago_method(): void
    {
        Http::fake();

        $this->assertFalse($this->gateway->createPayment($this->tenant, $this->order(), ['customer_email' => 'a@b.co', 'payment_method' => 'cash_on_delivery'])->success);
        $this->assertFalse($this->gateway->createPayment($this->tenant, $this->order(), ['customer_email' => 'a@b.co'])->success);

        Http::assertNothingSent();
    }

    public function test_a_card_without_token_or_with_a_hostile_brand_is_never_sent(): void
    {
        Http::fake();

        foreach ([
            ['payment_method_id' => 'visa'],
            ['token' => 'tok', 'payment_method_id' => 'VISA'],
            ['token' => 'tok', 'payment_method_id' => 'visa","x":"y'],
            ['token' => 'tok'],
            ['token' => '   ', 'payment_method_id' => 'visa'],
            ['token' => ['a'], 'payment_method_id' => 'visa'],
        ] as $form) {
            $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->cardData(['formData' => $form]));

            $this->assertFalse($result->success);
            $this->assertSame('missing_card_token', $result->meta['error']);
        }

        Http::assertNothingSent();
    }

    public function test_a_declined_card_returns_the_created_order_with_the_rejected_status(): void
    {
        // Sandbox real: cartão recusado = HTTP 402 com `errors[]` E a order em `data`.
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::declinedBody('ORDTEST000130', null, 'rejected_by_issuer'), 402)]);

        $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->cardData());

        $this->assertTrue($result->success, 'há uma order criada: o id precisa ser gravado');
        $this->assertSame('ORDTEST000130', $result->paymentId);
        $this->assertSame('rejected', $result->status);
        $this->assertSame('rejected_by_issuer', $result->statusDetail);
        $this->assertTrue($result->isDeclined());
        $this->assertSame('failed', $result->meta['gateway_details']['order_status']);
    }

    public function test_a_402_without_an_order_is_a_failure_not_a_decline(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::errorBody('failed', 'x'), 402)]);

        $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->cardData());

        $this->assertFalse($result->success);
        $this->assertSame(402, $result->errorCode);
    }

    public function test_create_payment_returns_failure_on_api_error_keeping_only_the_error_body(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::errorBody('property_value', 'Invalid value for property', ["'$.payer.email' - does not match pattern"]) + ['data' => ['secret' => 'nao-vai']], 400)]);

        $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->errorCode);
        $this->assertSame('property_value', $result->meta['error']['errors'][0]['code']);
        $this->assertArrayNotHasKey('data', $result->meta['error']);
        $this->assertArrayNotHasKey('reason', $result->meta, 'property_value é erro de dado, não de configuração');
    }

    public function test_configuration_errors_are_flagged_so_the_customer_sees_unavailable(): void
    {
        foreach (['invalid_credentials', 'payment_method_not_allowed_for_collector', 'unsupported_properties', 'forbidden'] as $code) {
            Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::errorBody($code, 'x'), 400)]);

            $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

            $this->assertSame('configuration', $result->meta['reason'] ?? null, $code);
        }
    }

    public function test_a_connection_failure_is_flagged_unreachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

        $this->assertFalse($result->success);
        $this->assertSame('unreachable', $result->meta['reason']);
    }

    public function test_a_2xx_without_an_order_id_is_a_failure(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(['status' => 'processed'], 201)]);

        $this->assertFalse($this->gateway->createPayment($this->tenant, $this->order(), $this->cardData())->success);
    }

    // ------------------------------------------------- Device ID e integrador

    public function test_a_valid_device_id_is_sent_and_a_hostile_one_is_not(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000131', 'pending', null), 201)]);
        $good = 'armor.0123456789abcdef0123456789abcdef.fedcba9876543210fedcba9876543210';

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['device_session_id' => $good]));
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-meli-session-id', $good));

        foreach ([null, '', "x\r\nX-Evil: 1", 'com espaço', str_repeat('a', 10240), ['array'], 12345678901] as $bad) {
            Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000132', 'pending', null), 201)]);

            $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['device_session_id' => $bad]));

            $this->assertTrue($result->success, 'um device id inválido nunca bloqueia o pagamento');
            Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! $r->hasHeader('X-meli-session-id'));
        }
    }

    public function test_the_integrator_header_is_sent_on_orders_only_when_configured(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000133', 'pending', null), 201)]);

        config(['services.mercadopago.integrator_id' => '']);
        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! $r->hasHeader('X-Integrator-Id'));

        config(['services.mercadopago.integrator_id' => 'dev_meu-cadastro']);
        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['idempotency_key' => 'outra']));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('X-Integrator-Id', 'dev_meu-cadastro'));
    }

    // ------------------------------------------------- Pix: expiração e QR assíncrono

    /** @return array<string, array{string, string}> */
    public static function pixExpirations(): array
    {
        return [
            'padrão' => ['PT30M', 'PT30M'],
            '1 hora' => ['PT1H', 'PT1H'],
            '1 dia' => ['P1D', 'P1D'],
            'limite máximo (30 dias)' => ['P30D', 'P30D'],
            'composta' => ['P1DT2H', 'P1DT2H'],
            'abaixo de 30 minutos cai no padrão' => ['PT10M', 'PT30M'],
            'acima de 30 dias cai no padrão' => ['P31D', 'PT30M'],
            'não é ISO 8601' => ['30', 'PT30M'],
            'texto' => ['meia hora', 'PT30M'],
            'vazio' => ['', 'PT30M'],
            'só P' => ['P', 'PT30M'],
            'T sem componentes' => ['PT', 'PT30M'],
            'injeção' => ['PT1H","x":"y', 'PT30M'],
        ];
    }

    #[DataProvider('pixExpirations')]
    public function test_the_pix_expiration_is_configurable_and_validated_against_the_documented_range(string $configured, string $expected): void
    {
        config(['services.mercadopago.pix_expiration' => $configured]);
        \App\Support\PixExpiration::resetNotice();
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000134', 'pending', null), 201)]);

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

        $this->assertSame($expected, $this->postedBody()['transactions']['payments'][0]['expiration_time']);
    }

    public function test_the_pix_expiration_is_in_the_checkout_payload_in_seconds_for_pix_only(): void
    {
        config(['services.mercadopago.pix_expiration' => 'PT45M']);

        $this->assertSame(2700, $this->gateway->checkoutPayload($this->tenant, PaymentMethod::MP_PIX, 100, 'u')['pixExpirationSeconds']);
        $this->assertNull($this->gateway->checkoutPayload($this->tenant, PaymentMethod::MP_CREDIT_CARD, 100, 'u')['pixExpirationSeconds']);
    }

    public function test_an_invalid_pix_expiration_warns_once_in_the_log(): void
    {
        config(['services.mercadopago.pix_expiration' => 'lixo']);
        \App\Support\PixExpiration::resetNotice();
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000135', 'pending', null), 201)]);
        $logs = [];
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$logs) {
            $logs[] = $e->message;
        });

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());
        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData(['idempotency_key' => 'b']));

        $this->assertCount(1, array_filter($logs, fn ($m) => str_contains($m, 'MERCADOPAGO_PIX_EXPIRATION')));
    }

    public function test_it_waits_a_short_bounded_time_for_the_pix_qr_and_returns_it_when_it_appears(): void
    {
        Sleep::fake();
        config(['services.mercadopago.pix_qr_wait' => 8]);
        $order = $this->order();
        $processing = MercadoPagoOrders::body('ORDTEST000136', 'in_process', $order);
        $ready = MercadoPagoOrders::body('ORDTEST000136', 'pending', $order);

        $gets = 0;
        Http::fake([
            'api.mercadopago.com/v1/orders/ORDTEST000136' => function () use (&$gets, $processing, $ready) {
                $gets++;

                return Http::response($gets < 3 ? $processing : $ready, 200);
            },
            'api.mercadopago.com/v1/orders' => Http::response($processing, 201),
        ]);

        $result = $this->gateway->createPayment($this->tenant, $order, $this->pixData());

        $this->assertSame(3, $gets, 'consulta até aparecer o QR e para');
        $this->assertSame('pending', $result->status);
        $this->assertSame(MercadoPagoOrders::QR_CODE, $result->meta['point_of_interaction']['transaction_data']['qr_code']);
        Sleep::assertSleptTimes(3);
    }

    public function test_the_qr_wait_is_bounded_and_returns_processing_when_the_qr_never_comes(): void
    {
        Sleep::fake();
        config(['services.mercadopago.pix_qr_wait' => 8]);
        $order = $this->order();
        $processing = MercadoPagoOrders::body('ORDTEST000137', 'in_process', $order);

        Http::fake([
            'api.mercadopago.com/v1/orders/ORDTEST000137' => Http::response($processing, 200),
            'api.mercadopago.com/v1/orders' => Http::response($processing, 201),
        ]);

        $result = $this->gateway->createPayment($this->tenant, $order, $this->pixData());

        $this->assertTrue($result->success);
        $this->assertSame('in_process', $result->status);
        $this->assertArrayNotHasKey('point_of_interaction', $result->meta);
        $gets = Http::recorded(fn (Request $r) => $r->method() === 'GET')->count();
        $this->assertGreaterThan(0, $gets);
        $this->assertLessThanOrEqual(5, $gets, 'poucas consultas');
        Sleep::assertSleptTimes($gets);
    }

    public function test_with_the_wait_disabled_there_is_no_extra_lookup(): void
    {
        config(['services.mercadopago.pix_qr_wait' => 0]);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000138', 'in_process', null), 201)]);

        $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

        Http::assertSentCount(1);
    }

    public function test_a_card_never_waits_for_a_qr(): void
    {
        Sleep::fake();
        config(['services.mercadopago.pix_qr_wait' => 8]);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000139', 'in_process', null), 201)]);

        $this->gateway->createPayment($this->tenant, $this->order(), $this->cardData());

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_failing_lookup_while_waiting_does_not_break_the_charge(): void
    {
        Sleep::fake();
        config(['services.mercadopago.pix_qr_wait' => 8]);
        Http::fake([
            'api.mercadopago.com/v1/orders/ORDTEST000140' => Http::response([], 500),
            'api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::body('ORDTEST000140', 'in_process', null), 201),
        ]);

        $result = $this->gateway->createPayment($this->tenant, $this->order(), $this->pixData());

        $this->assertTrue($result->success);
        $this->assertSame('in_process', $result->status);
    }

    // ------------------------------------------------- consulta (GET /v1/orders/{id})

    public function test_get_payment_queries_the_order_and_translates_the_status(): void
    {
        $order = $this->order();
        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST123456789' => Http::response(MercadoPagoOrders::body('ORDTEST123456789', 'approved', $order), 200)]);

        $result = $this->gateway->getPayment($this->tenant, 'ORDTEST123456789');

        $this->assertInstanceOf(PaymentResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('ORDTEST123456789', $result->paymentId);
        $this->assertSame('approved', $result->status);
        $this->assertSame('accredited', $result->statusDetail);
        $this->assertSame($order->id, $result->meta['metadata']['order_id'], 'o vínculo é o external_reference');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.mercadopago.com/v1/orders/ORDTEST123456789');
    }

    public function test_get_payment_never_queries_a_legacy_numeric_payments_id(): void
    {
        Http::fake();

        $this->assertNull($this->gateway->getPayment($this->tenant, '123456789'));
        $this->assertNull($this->gateway->getPayment($this->tenant, '../payments/1'));
        $this->assertNull($this->gateway->getPayment($this->tenant, 'ORD'));

        Http::assertNothingSent();
        $this->assertFalse($this->gateway->isLookupableId('123456789'));
        $this->assertTrue($this->gateway->isLookupableId('ORDTST01M5KDGNDJS4CK6T1QRT75SNMH'));
    }

    public function test_get_payment_returns_null_when_a_200_response_has_no_status(): void
    {
        // Corpo inesperado/truncado não é um estado: nada de "transição" para o status vazio.
        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST000000123' => Http::response(['id' => 'ORDTEST000000123'], 200)]);
        $this->assertNull($this->gateway->getPayment($this->tenant, 'ORDTEST000000123'));

        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST000000124' => Http::response(['id' => 'ORDTEST000000124', 'status' => ''], 200)]);
        $this->assertNull($this->gateway->getPayment($this->tenant, 'ORDTEST000000124'));
    }

    public function test_get_payment_returns_null_on_api_error(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST999999999' => Http::response(MercadoPagoOrders::errorBody('order_not_found', 'x'), 404)]);

        $this->assertNull($this->gateway->getPayment($this->tenant, 'ORDTEST999999999'));
    }

    public function test_an_unknown_status_never_becomes_paid_and_keeps_the_raw_value(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST000000555' => Http::response(MercadoPagoOrders::body('ORDTEST000000555', 'status_do_futuro', null), 200)]);
        $logs = [];
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$logs) {
            $logs[] = $e->message;
        });

        $result = $this->gateway->getPayment($this->tenant, 'ORDTEST000000555');

        $this->assertSame('in_process', $result->status, 'continua em consulta, nunca aprovado');
        $this->assertFalse($result->isApproved());
        $this->assertSame('status_do_futuro', $result->meta['gateway_details']['order_status']);
        $this->assertSame('unknown', $result->meta['gateway_details']['note']);
        $this->assertContains('payment.gateway.status_not_in_table', $logs);
    }

    public function test_handle_webhook_ignores_other_topics_including_the_old_payment_topic(): void
    {
        foreach (['merchant_order', 'payment', 'chargebacks'] as $type) {
            $result = $this->gateway->handleWebhook(['type' => $type, 'data' => ['id' => 'ORDTEST000000123']]);

            $this->assertInstanceOf(WebhookResult::class, $result);
            $this->assertFalse($result->processed);
            $this->assertStringContainsString('não suportado', $result->errorMessage, $type);
        }
    }

    public function test_handle_webhook_fails_without_the_order_id(): void
    {
        $result = $this->gateway->handleWebhook(['type' => 'order']);

        $this->assertFalse($result->processed);
        $this->assertStringContainsString('id da order ausente', $result->errorMessage);
    }

    public function test_handle_webhook_ignores_a_legacy_numeric_payments_id(): void
    {
        Http::fake();

        $result = $this->gateway->handleWebhook(['type' => 'order', 'data' => ['id' => '123456789']]);

        $this->assertFalse($result->processed);
        $this->assertFalse($result->retryable, 'um id da API antiga nunca será consultável: não pedir reenvio');
        Http::assertNothingSent();
    }

    public function test_handle_webhook_ignores_unknown_order(): void
    {
        $result = $this->gateway->handleWebhook(['type' => 'order', 'data' => ['id' => 'ORDTEST999999999']]);

        $this->assertFalse($result->processed);
        $this->assertStringContainsString('não encontrado', $result->errorMessage);
    }

    public function test_handle_webhook_updates_order_and_payment(): void
    {
        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'payment_status' => 'unpaid',
            'meta' => ['mercadopago' => ['payment_id' => 'ORDTEST123456789']],
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => 'ORDTEST123456789',
            'amount_cents' => $order->total_cents,
            'status' => 'pending',
        ]);

        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST123456789' => Http::response(MercadoPagoOrders::body('ORDTEST123456789', 'approved', $order), 200)]);

        $result = $this->gateway->handleWebhook(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => 'ORDTEST123456789']]);

        $this->assertInstanceOf(WebhookResult::class, $result);
        $this->assertTrue($result->processed);
        $this->assertEquals($order->id, $result->orderId);
        $this->assertEquals('paid', $result->paymentStatus);

        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
        $this->assertEquals('approved', $payment->refresh()->status);
        $this->assertSame('processed', $payment->meta['gateway_details']['order_status']);
    }

    public function test_the_webhook_never_trusts_the_payload_status(): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'payment_status' => 'unpaid']);
        Payment::create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'gateway' => 'mercadopago',
            'gateway_payment_id' => 'ORDTEST123456790', 'amount_cents' => $order->total_cents, 'status' => 'pending',
        ]);
        // O corpo diz "processed/accredited", a API (fonte da verdade) diz que ainda espera o Pix.
        Http::fake(['api.mercadopago.com/v1/orders/ORDTEST123456790' => Http::response(MercadoPagoOrders::body('ORDTEST123456790', 'pending', $order), 200)]);

        $this->gateway->handleWebhook([
            'type' => 'order', 'action' => 'order.processed',
            'data' => ['id' => 'ORDTEST123456790', 'status' => 'processed', 'status_detail' => 'accredited'],
        ]);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
    }

    public function test_validate_webhook_signature_returns_true_in_dev_without_secret(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue($this->gateway->validateWebhookSignature('ORDTEST123', [], null));
    }

    public function test_validate_webhook_signature_returns_false_without_header(): void
    {
        $this->assertFalse($this->gateway->validateWebhookSignature('ORDTEST123', [], 'secret-key'));
    }

    public function test_validate_webhook_signature_accepts_the_lowercase_manifest_and_the_literal_one(): void
    {
        $dataId = 'ORD01M28P44G5FG8RJPM579EH56FV';
        $requestId = 'request-123';
        $ts = '1234567890';
        $secretKey = 'my-secret-key';
        $sign = fn (string $id) => ['x-signature' => "ts={$ts},v1=".hash_hmac('sha256', "id:{$id};request-id:{$requestId};ts:{$ts};", $secretKey), 'x-request-id' => $requestId];

        // O MP assina o data.id alfanumérico em minúsculas (documentação de webhooks e SDK oficial)...
        $this->assertTrue($this->gateway->validateWebhookSignature($dataId, $sign(strtolower($dataId)), $secretKey));
        // ...e a forma literal também é aceita (as duas exigem o segredo).
        $this->assertTrue($this->gateway->validateWebhookSignature($dataId, $sign($dataId), $secretKey));
        // Outro id, outro request-id ou outro segredo não passam.
        $this->assertFalse($this->gateway->validateWebhookSignature('ORDOUTRO', $sign(strtolower($dataId)), $secretKey));
        $this->assertFalse($this->gateway->validateWebhookSignature($dataId, $sign(strtolower($dataId)), 'outro-segredo'));
        $this->assertFalse($this->gateway->validateWebhookSignature($dataId, ['x-request-id' => 'outro'] + $sign(strtolower($dataId)), $secretKey));
    }

    public function test_validate_webhook_signature_rejects_invalid_signature(): void
    {
        $headers = ['x-signature' => 'ts=1234567890,v1=invalid_hash', 'x-request-id' => 'request-123'];

        $this->assertFalse($this->gateway->validateWebhookSignature('ORDTEST123', $headers, 'secret-key'));
    }

    public function test_validate_webhook_signature_rejects_malformed_headers(): void
    {
        foreach (['', 'lixo', 'ts=1', 'v1=abc', 'ts=,v1='] as $bad) {
            $this->assertFalse($this->gateway->validateWebhookSignature('ORDTEST123', ['x-signature' => $bad], 'secret-key'), $bad);
        }
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

    public function test_parse_webhook_extracts_the_order_and_the_delivery_id_without_io(): void
    {
        $event = $this->gateway->parseWebhook(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => 'ORDTEST000000123']], ['x-request-id' => 'req-9']);
        $this->assertSame('ORDTEST000000123', $event->paymentId);
        $this->assertSame('req-9', $event->requestId);

        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'merchant_order', 'data' => ['id' => 'ORDTEST000000123']], [])->ignoredReason);
        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'payment', 'data' => ['id' => '123']], [])->ignoredReason);
        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'order'], [])->invalidReason);
        $this->assertFalse($this->gateway->parseWebhook(['type' => 'order', 'data' => 'x'], [])->isActionable());
        $this->assertNotNull($this->gateway->parseWebhook(['type' => 'order', 'data' => ['id' => 123456789]], [])->ignoredReason ?? $this->gateway->parseWebhook(['type' => 'order', 'data' => ['id' => 123456789]], [])->invalidReason);
    }

    public function test_validate_webhook_uses_the_secret_of_the_given_tenant_and_the_query_data_id(): void
    {
        $headers = function (string $secret, string $dataId = 'ord01m28p44g5fg8rjpm579eh56fv') {
            $hash = hash_hmac('sha256', "id:{$dataId};request-id:r1;ts:100;", $secret);

            return ['x-signature' => "ts=100,v1={$hash}", 'x-request-id' => 'r1'];
        };
        // O MP assina o `data.id` da QUERY (o PHP o entrega como `data_id`); o corpo repete o valor.
        $payload = ['data_id' => 'ORD01M28P44G5FG8RJPM579EH56FV', 'type' => 'order', 'data' => ['id' => 'ORD01M28P44G5FG8RJPM579EH56FV']];

        $this->assertTrue($this->gateway->validateWebhook($this->tenant, $payload, $headers('test-webhook-secret')));
        $this->assertFalse($this->gateway->validateWebhook($this->tenant, $payload, $headers('outro-secret')));
        // Só o corpo (sem query): usa o `data.id` do corpo.
        $this->assertTrue($this->gateway->validateWebhook($this->tenant, ['data' => ['id' => 'ORD01M28P44G5FG8RJPM579EH56FV']], $headers('test-webhook-secret')));
        // A assinatura cobre o id: trocar o id invalida, e query diferente do corpo é rejeitada.
        $this->assertFalse($this->gateway->validateWebhook($this->tenant, ['data' => ['id' => 'ORD999']], $headers('test-webhook-secret')));
        $this->assertFalse($this->gateway->validateWebhook($this->tenant, ['data_id' => 'ORD01M28P44G5FG8RJPM579EH56FV', 'data' => ['id' => 'ORDOUTRO']], $headers('test-webhook-secret')));
    }

    // ------------------------------------------------------------------ cancelamento de order (janela do Pix)

    public function test_the_gateway_declares_the_optional_cancel_capability(): void
    {
        $this->assertInstanceOf(\App\Contracts\CancelsPayments::class, $this->gateway);
    }

    public function test_cancel_posts_to_the_cancel_endpoint_without_a_body_and_with_the_idempotency_key(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(MercadoPagoOrders::body('ORDTEST123456789', 'cancelled'), 200)]);

        $result = $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', 'pix-expiry:abc');

        $this->assertSame(PaymentCancellation::CANCELED, $result->outcome);
        $this->assertTrue($result->isCanceled());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.mercadopago.com/v1/orders/ORDTEST123456789/cancel'
            && $r->body() === ''
            && $r->hasHeader('X-Idempotency-Key', 'pix-expiry:abc')
            && $r->hasHeader('Authorization', 'Bearer TEST-access-token'));
        Http::assertSentCount(1);
    }

    public function test_cancel_truncates_the_idempotency_key_to_the_128_character_limit(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response([], 200)]);

        $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', str_repeat('k', 300));

        Http::assertSent(fn (Request $r) => strlen($r->header('X-Idempotency-Key')[0]) === 128);
    }

    public function test_cancel_maps_order_already_canceled_to_already_canceled(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(MercadoPagoOrders::errorBody('order_already_canceled'), 409)]);

        $result = $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', 'k');

        $this->assertSame(PaymentCancellation::ALREADY_CANCELED, $result->outcome);
        $this->assertTrue($result->isCanceled());
    }

    #[DataProvider('cannotCancelResponses')]
    public function test_cancel_maps_cannot_cancel_order_to_not_cancelable_whatever_the_http_status(int $status): void
    {
        // 409 foi o observado na sandbox; a documentação lista `cannot_cancel_order` entre os 400.
        Http::fake(['api.mercadopago.com/*' => Http::response(MercadoPagoOrders::errorBody('cannot_cancel_order'), $status)]);

        $result = $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', 'k');

        $this->assertSame(PaymentCancellation::NOT_CANCELABLE, $result->outcome);
        $this->assertFalse($result->isCanceled());
        $this->assertSame('cannot_cancel_order', $result->errorCode);
    }

    /** @return array<string, array{0: int}> */
    public static function cannotCancelResponses(): array
    {
        return ['http 409' => [409], 'http 400' => [400]];
    }

    public function test_cancel_reports_the_current_status_when_the_error_carries_it(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(MercadoPagoOrders::errorBody('cannot_cancel_order') + ['data' => ['status' => 'processed']], 409)]);

        $this->assertSame('processed', $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', 'k')->currentStatus);
    }

    #[DataProvider('unavailableResponses')]
    public function test_cancel_is_unavailable_on_network_failure_server_errors_and_unknown_errors(callable $response, ?int $httpStatus): void
    {
        Http::fake(['api.mercadopago.com/*' => $response()]);

        $result = $this->gateway->cancelPayment($this->tenant, 'ORDTEST123456789', 'k');

        $this->assertSame(PaymentCancellation::UNAVAILABLE, $result->outcome);
        $this->assertFalse($result->isCanceled());
        $this->assertSame($httpStatus, $result->httpStatus);
    }

    /** @return array<string, array{0: callable, 1: int|null}> */
    public static function unavailableResponses(): array
    {
        return [
            'timeout' => [fn () => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28'), null],
            'http 500' => [fn () => Http::response(['message' => 'boom'], 500), 500],
            'http 503' => [fn () => Http::response('', 503), 503],
            'http 401' => [fn () => Http::response(MercadoPagoOrders::errorBody('invalid_credentials'), 401), 401],
            'http 404' => [fn () => Http::response(MercadoPagoOrders::errorBody('not_found'), 404), 404],
            '409 de outra causa (idempotência)' => [fn () => Http::response(MercadoPagoOrders::errorBody('idempotency_key_already_used'), 409), 409],
        ];
    }

    public function test_cancel_never_calls_the_api_for_ids_of_the_old_api_or_without_credentials(): void
    {
        Http::fake();

        $this->assertSame(PaymentCancellation::UNAVAILABLE, $this->gateway->cancelPayment($this->tenant, '1234567890', 'k')->outcome);

        $bare = Tenant::factory()->create(['settings' => []]);
        $this->assertSame(PaymentCancellation::UNAVAILABLE, $this->gateway->cancelPayment($bare, 'ORDTEST123456789', 'k')->outcome);

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\PaymentFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * Falha de cobrança: (3) observabilidade — log `payment.charge.failed` sem segredo; (4) UX —
 * falha de CONFIGURAÇÃO (que o cliente não resolve) não pede "confira os dados".
 *
 * O caso real: HTTP 400, `cause[0].code = 4020` ("notificaction_url attribute must be url valid").
 */
class ChargeFailureTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const TOKEN = 'TEST-access-token-mp-loja';

    private const EMAIL = 'maria@example.com';

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
        $this->product = $this->product($this->tenant, 1000, stock: 5);
    }

    private function pay(array $body = []): TestResponse
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody($body));
    }

    private function fakeMp(array $json, int $status): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response($json, $status)]);
    }

    /**
     * Falha de CONFIGURAÇÃO real da API de Orders (observada na sandbox: a conta de teste não aceita
     * o meio de pagamento). O cliente não resolve isso.
     *
     * @return array<string, mixed>
     */
    private function errorConfiguration(): array
    {
        return MercadoPagoOrders::errorBody('payment_method_not_allowed_for_collector', 'The payment method is not allowed for this collector.');
    }

    private function assertCancelledAndStockReturned(): void
    {
        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(5, (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity'));
    }

    // ------------------------------------------------------------------ (3) log

    public function test_a_4xx_from_the_gateway_logs_payment_charge_failed_as_warning_with_the_diagnosis(): void
    {
        $this->fakeMp($this->errorConfiguration(), 400);
        $logs = $this->captureLogs();

        $this->pay()->assertStatus(422);

        $entries = $this->logsNamed($logs, 'payment.charge.failed');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);

        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $payment = Payment::withoutTenantScope()->sole();
        $context = $entries[0]['context'];

        $this->assertSame($this->tenant->id, $context['tenant_id']);
        $this->assertSame($order->id, $context['order_id']);
        $this->assertSame($payment->id, $context['payment_id']);
        $this->assertSame('mercadopago', $context['gateway']);
        $this->assertSame(400, $context['http_status']);
        $this->assertSame('payment_method_not_allowed_for_collector', $context['gateway_error']);
        $this->assertSame('The payment method is not allowed for this collector.', $context['gateway_message']);
        $this->assertSame('payment_method_not_allowed_for_collector', $context['causes'][0]['code']);
        $this->assertSame('The payment method is not allowed for this collector.', $context['causes'][0]['description']);
        $this->assertSame('configuration', $context['reason']);
        $this->assertSame('service', $context['kind']);
    }

    public function test_a_5xx_from_the_gateway_logs_at_error_level(): void
    {
        $this->fakeMp(MercadoPagoOrders::errorBody('internal_error', 'Internal server error'), 500);
        $logs = $this->captureLogs();

        $this->pay()->assertStatus(422);

        $entries = $this->logsNamed($logs, 'payment.charge.failed');
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertSame(500, $entries[0]['context']['http_status']);
    }

    public function test_a_gateway_timeout_logs_at_error_level_without_http_status(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $logs = $this->captureLogs();

        $this->pay()->assertStatus(422);

        $entries = $this->logsNamed($logs, 'payment.charge.failed');
        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0]['level']);
        $this->assertArrayNotHasKey('http_status', $entries[0]['context']);
        $this->assertSame('unreachable', $entries[0]['context']['reason']);
    }

    public function test_a_rejected_payment_logs_the_status_detail_as_warning(): void
    {
        // Cartão recusado: HTTP 402 com a order criada em `data` (formato real de Orders).
        $this->fakeMp(MercadoPagoOrders::declinedBody('ORDTEST555005', null, 'insufficient_amount'), 402);
        $logs = $this->captureLogs();

        $this->pay()->assertStatus(422);

        $entries = $this->logsNamed($logs, 'payment.charge.failed');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertSame('rejected', $entries[0]['context']['gateway_status']);
        $this->assertSame('insufficient_amount', $entries[0]['context']['status_detail']);
        $this->assertSame('customer', $entries[0]['context']['kind']);
    }

    public function test_the_failure_log_never_carries_the_token_the_customer_email_card_data_or_the_payload(): void
    {
        // O MP às vezes ecoa dado do cliente/credencial na descrição: o log mascara.
        $this->fakeMp([
            'errors' => [
                ['code' => 'property_value', 'message' => 'invalid payer '.self::EMAIL.' for '.self::TOKEN, 'details' => ["'$.payer.email' ".self::EMAIL.' does not match pattern; token '.self::TOKEN]],
                ['code' => 'invalid_card_token', 'message' => 'invalid card_token_id card-token-0123456789abcdef0123456789abcdef; card 4509 9535 6623 3704'],
            ],
        ], 400);
        $logs = $this->captureLogs();

        $this->pay(['formData' => ['token' => 'card-token-0123456789abcdef0123456789abcdef', 'payment_method_id' => 'visa']])->assertStatus(422);

        $entries = $this->logsNamed($logs, 'payment.charge.failed');
        $this->assertCount(1, $entries);

        $dump = (string) json_encode($entries);
        foreach ([self::TOKEN, self::EMAIL, 'maria', 'card-token-0123456789abcdef', '4509 9535', 'Bearer', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "o log vazou: {$secret}");
        }

        foreach (['formData', 'payload', 'payer', 'request', 'headers', 'token', 'email'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $entries[0]['context']);
        }

        // ...mas o diagnóstico continua lá.
        $this->assertSame(['property_value', 'invalid_card_token'], array_column($entries[0]['context']['causes'], 'code'));
        $this->assertStringContainsString('[email]', $entries[0]['context']['causes'][0]['description']);
    }

    public function test_no_failure_log_is_emitted_on_success(): void
    {
        $this->fakeMpOrderCreation('ORDTEST777001', 'pending');
        $logs = $this->captureLogs();

        $this->pay()->assertOk();

        $this->assertSame([], $this->logsNamed($logs, 'payment.charge.failed'));
    }

    public function test_a_failed_log_write_does_not_stop_the_compensation(): void
    {
        $this->fakeMp($this->errorConfiguration(), 400);
        \Illuminate\Support\Facades\Log::listen(function (\Illuminate\Log\Events\MessageLogged $event) {
            if ($event->message === 'payment.charge.failed') {
                throw new \RuntimeException('disco cheio');
            }
        });

        $this->pay()->assertStatus(422);

        $this->assertCancelledAndStockReturned();
    }

    // ------------------------------------------------------------------ (4) mensagem

    public function test_a_configuration_failure_says_online_payment_is_unavailable_and_hides_the_details(): void
    {
        $this->fakeMp($this->errorConfiguration(), 400);

        $response = $this->pay()->assertStatus(422);

        $this->assertSame(PaymentFailure::UNAVAILABLE_MESSAGE, $response->json('message'));
        $this->assertSame('O pagamento online está indisponível no momento. Escolha outra forma de pagamento ou fale com a loja.', $response->json('message'));
        $this->assertSame('payment_unavailable', $response->json('error'));

        $body = (string) $response->getContent();
        foreach (['payment_method_not_allowed', 'collector', 'errors', 'localhost', self::TOKEN] as $detail) {
            $this->assertStringNotContainsString($detail, $body, "detalhe técnico vazou ao cliente: {$detail}");
        }

        // como antes: pedido cancelado, estoque devolvido, falha registrada na tentativa
        $this->assertCancelledAndStockReturned();
        $this->assertSame('failed', Payment::withoutTenantScope()->sole()->status);
    }

    /** @return array<int|string, array{int, array<string, mixed>}> */
    public static function serviceSideResponses(): array
    {
        $err = fn (string $code) => MercadoPagoOrders::errorBody($code, 'x');

        return [
            '500 internal_error' => [500, $err('internal_error')],
            '502 sem corpo' => [502, []],
            '503' => [503, ['message' => 'unavailable']],
            '401 credencial (invalid_credentials)' => [401, $err('invalid_credentials')],
            '401 código numérico' => [401, $err('401')],
            '403 permissão' => [403, $err('forbidden')],
            '403 conta bloqueada' => [403, $err('PA_UNAUTHORIZED_RESULT_FROM_POLICIES')],
            '400 meio de pagamento não permitido para a conta' => [400, $err('payment_method_not_allowed_for_collector')],
            '400 propriedade não suportada (corpo que NÓS montamos mal)' => [400, MercadoPagoOrders::errorBody('unsupported_properties', 'Properties not supported', ["additionalProperties '$.metadata' not allowed"])],
            '400 propriedade obrigatória ausente' => [400, $err('required_properties')],
            '400 cabeçalho inválido' => [400, $err('invalid_header_value')],
            '400 total não bate' => [400, $err('invalid_total_amount')],
            '429 cota' => [429, $err('usage_quota_exceeded')],
            '409 chave de idempotência já usada' => [409, $err('idempotency_key_already_used')],
            '423 chave bloqueada' => [423, $err('resource_locked')],
        ];
    }

    /** @param  array<string, mixed>  $json */
    #[DataProvider('serviceSideResponses')]
    public function test_service_side_errors_use_the_unavailable_message(int $status, array $json): void
    {
        $this->fakeMp($json, $status);

        $this->pay()->assertStatus(422)->assertJsonPath('message', PaymentFailure::UNAVAILABLE_MESSAGE);

        $this->assertCancelledAndStockReturned();
    }

    public function test_a_gateway_timeout_uses_the_unavailable_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->pay()->assertStatus(422)->assertJsonPath('message', PaymentFailure::UNAVAILABLE_MESSAGE);

        $this->assertCancelledAndStockReturned();
    }

    /** @return array<int|string, array{int, array<string, mixed>}> */
    public static function customerSideResponses(): array
    {
        return [
            'e-mail inválido' => [400, MercadoPagoOrders::errorBody('property_value', 'Invalid value for property', ["'$.payer.email' - does not match pattern"])],
            'tipo de campo inválido' => [400, MercadoPagoOrders::errorBody('property_type', 'Incorrect type for property', ["'$.total_amount' - expected string, but got number"])],
            'propriedades inválidas' => [400, MercadoPagoOrders::errorBody('invalid_properties', 'x')],
            'várias causas de campo' => [400, ['errors' => [['code' => 'property_value', 'message' => 'a'], ['code' => 'property_type', 'message' => 'b']]]],
            '400 sem causa conhecida (na dúvida, mensagem atual)' => [400, ['message' => 'bad request', 'error' => 'bad_request']],
            '400 sem corpo' => [400, []],
            'código desconhecido' => [400, MercadoPagoOrders::errorBody('algo_que_nao_conhecemos', '?')],
            '404' => [404, MercadoPagoOrders::errorBody('order_not_found', 'x')],
            '422' => [422, ['error' => 'unprocessable']],
        ];
    }

    /** @param  array<string, mixed>  $json */
    #[DataProvider('customerSideResponses')]
    public function test_customer_data_errors_keep_the_current_message(int $status, array $json): void
    {
        $this->fakeMp($json, $status);

        $response = $this->pay()->assertStatus(422);

        $this->assertSame(PaymentFailure::CUSTOMER_MESSAGE, $response->json('message'));
        $this->assertSame('Não foi possível concluir o pagamento. O pedido foi cancelado; confira os dados e tente novamente.', $response->json('message'));
        $this->assertNotSame('payment_unavailable', $response->json('error'));
        $this->assertCancelledAndStockReturned();
    }

    public function test_a_card_rejected_by_the_issuer_keeps_the_current_message(): void
    {
        $this->fakeMp(MercadoPagoOrders::declinedBody('ORDTEST555005', null, 'rejected_by_issuer'), 402);

        $this->pay()->assertStatus(422)->assertJsonPath('message', PaymentFailure::CUSTOMER_MESSAGE);

        // A order recusada existe no MP: o id fica gravado e o estado é o final (recusado).
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('ORDTEST555005', $payment->gateway_payment_id);
        $this->assertSame('rejected', $payment->status);
        $this->assertSame('rejected_by_issuer', $payment->status_detail);

        $this->assertCancelledAndStockReturned();
    }

    public function test_a_card_payment_without_token_keeps_the_current_message(): void
    {
        Http::fake();

        $this->pay(['mp_method' => 'mp_credit_card', 'formData' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', PaymentFailure::CUSTOMER_MESSAGE);

        Http::assertNothingSent();
        $this->assertCancelledAndStockReturned();
    }
}

<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * E3.4 — pedido + pagamento consistentes (ARC-007) e compensação por falha.
 *
 * Todos os cenários passam pelo endpoint real `POST /{tenant}/process_payment`.
 */
class PaymentConsistencyTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
    }

    private function pay(\App\Models\Product $product, int $qty = 1, array $body = [], string $nonce = 'nonce-fixo'): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $product, $qty, $nonce))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody($body));
    }

    private function onlyOrder(): Order
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function stockOf(\App\Models\Product $p): int
    {
        return (int) DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    // ---------------------------------------------------------- tentativa ANTES da chamada

    public function test_the_payment_attempt_is_persisted_before_the_gateway_is_called_with_the_idempotency_key(): void
    {
        $product = $this->product($this->tenant);
        $seen = [];

        Http::fake(function (Request $request) use (&$seen) {
            // Dentro da chamada externa: a tentativa JÁ existe e é a chave enviada ao MP.
            $attempt = Payment::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();
            $seen = [
                'attempt' => $attempt?->only(['status', 'idempotency_key', 'gateway_payment_id', 'amount_cents']),
                'header' => $request->header('X-Idempotency-Key')[0] ?? null,
                'transaction_level' => DB::transactionLevel(),
            ];

            return Http::response(['id' => 555001, 'status' => 'pending', 'status_detail' => 'pending_waiting_transfer', 'metadata' => []], 201);
        });

        $this->pay($product, 2)->assertOk();

        $this->assertNotEmpty($seen['attempt'], 'o Payment só foi gravado DEPOIS da chamada ao gateway');
        $this->assertSame('created', $seen['attempt']['status']);
        $this->assertNull($seen['attempt']['gateway_payment_id']);
        $this->assertSame(5000, $seen['attempt']['amount_cents']);
        $this->assertNotEmpty($seen['attempt']['idempotency_key']);
        $this->assertSame($seen['attempt']['idempotency_key'], $seen['header'], 'a chave persistida não é a enviada ao gateway');
        // RefreshDatabase mantém UMA transação de teste; qualquer nível acima = transação do app aberta.
        $this->assertSame(1, $seen['transaction_level'], 'transação de banco aberta durante a chamada HTTP externa');

        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('555001', $payment->gateway_payment_id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame($seen['attempt']['idempotency_key'], $payment->idempotency_key);
    }

    public function test_the_amount_charged_comes_from_the_order_never_from_the_client(): void
    {
        $product = $this->product($this->tenant, 2500);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 555002, 'status' => 'pending', 'metadata' => []], 201)]);

        $this->pay($product, 2, ['formData' => ['transaction_amount' => 0.01, 'token' => 't'], 'amount' => 1, 'total_cents' => 1])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && (float) $r['transaction_amount'] === 50.0);
        $this->assertSame(5000, Payment::withoutTenantScope()->sole()->amount_cents);
        $this->assertSame(5000, $this->onlyOrder()->total_cents);
    }

    public function test_notification_url_carries_the_tenant_identifier(): void
    {
        $product = $this->product($this->tenant);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 555003, 'status' => 'pending', 'metadata' => []], 201)]);

        $this->pay($product)->assertOk();

        Http::assertSent(fn (Request $r) => $r['notification_url'] === url('/webhooks/mercadopago/'.$this->tenant->id));
    }

    public function test_approved_payment_is_consolidated_in_order_and_payment(): void
    {
        $product = $this->product($this->tenant);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555004, 'status' => 'approved', 'status_detail' => 'accredited', 'payment_method_id' => 'visa', 'metadata' => [],
        ], 201)]);

        $this->pay($product)->assertOk()->assertJson(['ok' => true, 'status' => 'approved']);

        $order = $this->onlyOrder();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame('555004', $order->meta['mercadopago']['payment_id']);
        $this->assertSame('approved', Payment::withoutTenantScope()->sole()->status);
        $this->assertEmpty(session()->get(CartService::sessionKey($this->tenant), []), 'carrinho deveria ser limpo no sucesso');
    }

    // ---------------------------------------------------------- falha do gateway

    public function test_a_declined_payment_cancels_the_order_returns_the_stock_once_and_marks_the_payment_failed(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['message' => 'bad request', 'error' => 'bad_request'], 400)]);

        $this->pay($product, 2)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');

        $order = $this->onlyOrder();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame(5, $this->stockOf($product), 'estoque não devolvido (ou devolvido em dobro)');
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('failed', $payment->status);
        $this->assertNotEmpty($payment->idempotency_key);
        $this->assertNotEmpty(session()->get(CartService::sessionKey($this->tenant)), 'carrinho preservado para nova tentativa');
    }

    public function test_a_payment_rejected_by_the_issuer_cancels_the_order_even_though_the_api_answered_201(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 3);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555005, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount', 'metadata' => [],
        ], 201)]);

        $this->pay($product)->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELLED, $this->onlyOrder()->status);
        $this->assertSame(3, $this->stockOf($product));
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('rejected', $payment->status);
        $this->assertSame('555005', $payment->gateway_payment_id);
        $this->assertSame('cc_rejected_insufficient_amount', $payment->status_detail);
    }

    public function test_a_gateway_timeout_cancels_the_order_and_returns_the_stock(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 4);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->pay($product, 3)->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELLED, $this->onlyOrder()->status);
        $this->assertSame(4, $this->stockOf($product));
        $this->assertSame('failed', Payment::withoutTenantScope()->sole()->status);
    }

    public function test_an_unexpected_exception_from_the_gateway_is_compensated_and_never_a_500(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 4);
        $this->mock(MercadoPagoGateway::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('createPayment')->andThrow(new \RuntimeException('socket exploded'));
        });
        $logs = $this->captureLogs();

        $this->pay($product, 1)->assertStatus(422);

        $this->assertSame(OrderStatus::CANCELLED, $this->onlyOrder()->status);
        $this->assertSame(4, $this->stockOf($product));
        $this->assertSame('failed', Payment::withoutTenantScope()->sole()->status);
        $this->assertCount(1, $this->logsNamed($logs, 'payment.gateway_exception'));
    }

    public function test_a_cart_that_cannot_be_charged_leaves_no_order_behind_when_stock_is_short(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 1);
        Http::fake();

        $this->pay($product, 3)->assertStatus(422)->assertJson(['error' => 'insufficient_stock']);

        $this->assertSame(0, Order::withoutTenantScope()->count());
        $this->assertSame(0, Payment::withoutTenantScope()->count());
        $this->assertSame(1, $this->stockOf($product));
        Http::assertNothingSent();
    }

    public function test_after_a_failure_the_retry_gets_a_new_order_a_new_idempotency_key_and_a_new_charge(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        $keys = [];
        Http::fake(function (Request $request) use (&$keys) {
            $keys[] = $request->header('X-Idempotency-Key')[0] ?? null;

            return count($keys) === 1
                ? Http::response(['status' => 'rejected', 'id' => 555006, 'metadata' => []], 201)
                : Http::response(['status' => 'approved', 'id' => 555007, 'metadata' => []], 201);
        });

        $this->pay($product)->assertStatus(422);
        $this->pay($product)->assertOk()->assertJson(['ok' => true]);

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'o MP reaproveitaria a resposta de falha da chave antiga');
        $this->assertSame(2, Payment::withoutTenantScope()->count());
        $this->assertSame(['cancelled', 'pending'], Order::withoutTenantScope()->orderBy('number')->get()->map(fn ($o) => $o->status->value)->all());
        $this->assertSame(4, $this->stockOf($product), 'só o pedido vivo mantém a baixa');
    }

    // ---------------------------------------------------------- duplo submit

    public function test_double_submit_of_the_same_checkout_creates_one_order_one_payment_and_one_charge(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 555008, 'status' => 'pending', 'status_detail' => 'pending_waiting_transfer', 'metadata' => [],
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']]], 201)]);

        $first = $this->pay($product, 2)->assertOk();
        // 2ª requisição leu a sessão ANTES de a 1ª limpar o carrinho (mesmo carrinho e nonce).
        $second = $this->pay($product, 2)->assertOk();

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertSame('QR', $second->json('point_of_interaction.transaction_data.qr_code'), 'o replay precisa devolver o QR do PIX');
        $this->assertSame(1, Order::withoutTenantScope()->count());
        $this->assertSame(1, Payment::withoutTenantScope()->count());
        $this->assertSame(3, $this->stockOf($product), 'a baixa de estoque aconteceu mais de uma vez');
        Http::assertSentCount(1);
    }

    public function test_double_submit_while_the_first_is_in_flight_does_not_charge_again(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        $duplicate = null;

        Http::fake(function () use ($product, &$duplicate) {
            // Reentrada: a 2ª requisição chega enquanto a 1ª espera o gateway.
            $duplicate = $this->pay($product, 1);

            return Http::response(['id' => 555009, 'status' => 'pending', 'metadata' => []], 201);
        });

        $this->pay($product, 1)->assertOk();

        $this->assertNotNull($duplicate);
        $duplicate->assertStatus(409)->assertJson(['error' => 'payment_in_progress']);
        $this->assertSame(1, Order::withoutTenantScope()->count());
        $this->assertSame(1, Payment::withoutTenantScope()->count());
        $this->assertSame(4, $this->stockOf($product));
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------- aprovado + falha de persistência

    public function test_approved_charge_followed_by_a_persistence_failure_is_logged_as_an_incident_and_reconciled_by_the_webhook(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555010, 'status' => 'approved', 'status_detail' => 'accredited', 'metadata' => [],
        ], 201)]);
        $logs = $this->captureLogs();

        // Falha ao consolidar: o banco recusa a gravação do resultado no Payment.
        $db = new class
        {
            public bool $down = true;
        };
        Payment::updating(function () use ($db) {
            if ($db->down) {
                throw new \RuntimeException('simulated: connection to database lost');
            }
        });

        $response = $this->pay($product, 2);

        // O cliente PAGOU: nada de 5xx nem de pedido cancelado — o webhook concilia.
        $response->assertStatus(202)->assertJson(['ok' => true, 'reconciling' => true]);
        $order = $this->onlyOrder();
        $this->assertSame(OrderStatus::PENDING, $order->status, 'pedido de um pagamento aprovado não pode ser cancelado');
        $this->assertSame(3, $this->stockOf($product), 'a baixa permanece: o cliente pagou');

        $incidents = $this->logsNamed($logs, 'payment.incident.charge_not_consolidated');
        $this->assertCount(1, $incidents);
        $this->assertSame('critical', $incidents[0]['level']);
        $this->assertSame($order->id, $incidents[0]['context']['order_id']);
        $this->assertSame($this->tenant->id, $incidents[0]['context']['tenant_id']);
        $this->assertSame('555010', $incidents[0]['context']['gateway_payment_id']);
        $this->assertSame('approved', $incidents[0]['context']['gateway_status']);
        $this->assertNotEmpty($incidents[0]['context']['idempotency_key']);

        // Reconciliação: o webhook (banco de volta) fecha o pedido como pago.
        $db->down = false;
        $this->fakeMpPaymentLookup('555010', 'approved', $order);

        $this->postMpWebhook($this->tenant, '555010', $this->mpSignedHeaders('555010'))
            ->assertOk()->assertJson(['ok' => true, 'transitioned' => true]);

        $this->assertSame(PaymentStatus::PAID, Order::withoutTenantScope()->findOrFail($order->id)->payment_status);
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('approved', $payment->status);
        $this->assertSame('555010', $payment->gateway_payment_id);
    }

    public function test_a_late_approval_after_a_timeout_cancel_is_flagged_for_refund(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 4);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->pay($product)->assertStatus(422);
        $order = $this->onlyOrder();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);

        // O MP cobrou mesmo assim (timeout ambíguo) e avisa por webhook.
        $this->fakeMpPaymentLookup('555011', 'approved', $order);
        $logs = $this->captureLogs();

        $this->postMpWebhook($this->tenant, '555011', $this->mpSignedHeaders('555011'))->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->onlyOrder()->status);
        $this->assertSame(PaymentStatus::PAID, $this->onlyOrder()->payment_status);
        $this->assertSame(4, $this->stockOf($product), 'estoque não pode ser rebaixado na reconciliação');
        $this->assertCount(1, $this->logsNamed($logs, 'payment.incident.paid_on_cancelled_order'));
    }
}

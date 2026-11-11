<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * Sem webhook (APP_URL local), quem decide `paid` é a consulta ativa à API do MP, feita pelo
 * `GET /{tenant}/checkout/status` e aplicada pelo MESMO caminho idempotente do webhook.
 */
class PaymentReconciliationTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const PAYMENT_ID = 'ORDTEST777001';

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
        $this->product = $this->product($this->tenant, 1000, stock: 5);
        RateLimiter::clear('checkout-status');
        Cache::flush();
    }

    /** O navegador cria um PIX (pedido pendente, estoque baixado de 5 para 3). */
    private function createPix(): Order
    {
        $this->fakeMpOrderCreation(self::PAYMENT_ID, 'pending');

        $this
            ->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();

        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function poll(string $slug = 'mp-loja'): TestResponse
    {
        return $this->getJson('/'.$slug.'/checkout/status');
    }

    private function stockOf(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }

    private function events(): int
    {
        return DB::table('payment_webhook_events')->where('gateway_payment_id', self::PAYMENT_ID)->count();
    }

    /** Quantas consultas `GET /v1/orders/{id}` foram feitas ao MP. */
    private function mpLookups(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/v1/orders/'.self::PAYMENT_ID))->count();
    }

    private function mpSays(string $status, Order $order, array $overrides = []): void
    {
        $this->fakeMpPaymentLookup(self::PAYMENT_ID, $status, $order, $overrides);
    }

    private function nextPollWindow(): void
    {
        $this->travel(PaymentReconciler::THROTTLE_SECONDS + 1)->seconds();
    }

    // ------------------------------------------------------------------ aprovado

    public function test_a_pending_pix_that_mercado_pago_reports_approved_becomes_paid_without_any_webhook(): void
    {
        $order = $this->createPix();
        $this->assertSame(3, $this->stockOf());
        $this->mpSays('approved', $order);

        $this->poll()->assertOk()->assertExactJson(['status' => 'paid']);

        $order = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('approved', $payment->status);
        $this->assertSame(self::PAYMENT_ID, $payment->gateway_payment_id);
        $this->assertNotNull($payment->meta['last_reconciled_at'] ?? null);
        $this->assertArrayNotHasKey('last_webhook_at', $payment->meta);
        $this->assertSame(1, $this->events(), 'UMA transição registrada');
        $this->assertSame(3, $this->stockOf(), 'pagamento aprovado não mexe no estoque');
    }

    public function test_the_next_polls_after_paid_do_not_call_mercado_pago_again(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);

        $this->poll()->assertExactJson(['status' => 'paid']);
        $this->nextPollWindow();
        $this->poll()->assertExactJson(['status' => 'paid']);
        $this->nextPollWindow();
        $this->poll()->assertExactJson(['status' => 'paid']);

        $this->assertSame(1, $this->mpLookups(), 'pedido decidido não é reconsultado');
        $this->assertSame(1, $this->events());
    }

    public function test_a_webhook_arriving_after_the_reconciliation_is_a_no_op(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);
        $this->poll()->assertExactJson(['status' => 'paid']);

        $response = $this->postMpWebhook($this->tenant, self::PAYMENT_ID, $this->mpSignedHeaders(self::PAYMENT_ID))->assertOk();

        $this->assertNotTrue($response->json('transitioned'), 'o webhook reaplicou a transição');
        $this->assertSame(1, $this->events());
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertSame(3, $this->stockOf());
    }

    public function test_a_reconciliation_after_the_webhook_is_a_no_op_too(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);
        $this->postMpWebhook($this->tenant, self::PAYMENT_ID, $this->mpSignedHeaders(self::PAYMENT_ID))->assertOk()->assertJsonPath('transitioned', true);

        $this->poll()->assertExactJson(['status' => 'paid']);

        $this->assertSame(1, $this->events());
    }

    public function test_a_still_pending_payment_stays_pending_and_records_no_transition(): void
    {
        $order = $this->createPix();
        $this->mpSays('pending', $order, ['status_detail' => 'pending_waiting_transfer']);

        $this->assertPendingPoll($this->poll()->assertOk());

        $this->assertSame(0, $this->events());
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(3, $this->stockOf());
    }

    // ------------------------------------------------------------------ recusa / expiração

    /** @return array<string, array{string, string, string}> o que o MP diz, o status gravado e o detalhe gravado */
    public static function declined(): array
    {
        return [
            'failed (recusado)' => ['rejected', 'rejected', 'rejected_by_issuer'],
            'canceled (cancelado)' => ['cancelled', 'cancelled', 'canceled_transaction'],
            // PIX vencido: order `canceled/expired` e transação `expired/expired` (Orders).
            'expired (Pix vencido)' => ['expired', 'cancelled', 'expired'],
        ];
    }

    #[DataProvider('declined')]
    public function test_a_declined_or_expired_payment_cancels_the_order_and_returns_the_stock_exactly_once(string $mpSays, string $status, string $detail): void
    {
        $order = $this->createPix();
        $this->assertSame(3, $this->stockOf());
        $this->mpSays($mpSays, $order);

        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled']);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
        $this->assertSame(5, $this->stockOf(), 'estoque devolvido');
        $this->assertSame($status, Payment::withoutTenantScope()->sole()->status);
        $this->assertSame($detail, Payment::withoutTenantScope()->sole()->status_detail);

        // consultas e webhooks posteriores não devolvem de novo
        $this->nextPollWindow();
        $this->poll()->assertExactJson(['status' => 'cancelled']);
        $this->postMpWebhook($this->tenant, self::PAYMENT_ID, $this->mpSignedHeaders(self::PAYMENT_ID))->assertOk();
        $this->assertSame(5, $this->stockOf(), 'estoque devolvido mais de uma vez');
        $this->assertSame(1, $this->events());
    }

    // ------------------------------------------------------------------ MP fora do ar

    /** @return array<int|string, array{\Closure}> */
    public static function mpOutages(): array
    {
        return [
            'timeout' => [fn () => Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'))],
            '500' => [fn () => Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'internal'], 500)])],
            '503' => [fn () => Http::fake(['api.mercadopago.com/*' => Http::response('', 503)])],
            '401' => [fn () => Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'invalid token'], 401)])],
            'resposta sem status' => [fn () => Http::fake(['api.mercadopago.com/*' => Http::response(['id' => 1], 200)])],
        ];
    }

    #[DataProvider('mpOutages')]
    public function test_a_gateway_outage_never_breaks_the_endpoint(\Closure $outage): void
    {
        $order = $this->createPix();
        $logs = $this->captureLogs();
        // `Http::fake()` acumula e vence o primeiro stub que casa: parte de uma fábrica limpa.
        Http::swap(new HttpFactory);
        $outage();

        $this->assertPendingPoll($this->poll()->assertOk());

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(0, $this->events());
        $this->assertSame(3, $this->stockOf());

        $entries = $this->logsNamed($logs, 'payment.reconcile.gateway_unavailable');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertSame($this->tenant->id, $entries[0]['context']['tenant_id']);
        $this->assertSame($order->id, $entries[0]['context']['order_id']);
        $this->assertStringNotContainsString('TEST-access-token', (string) json_encode($logs->getArrayCopy()), 'o log vazou o token');
    }

    public function test_it_recovers_when_the_gateway_comes_back(): void
    {
        $order = $this->createPix();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertPendingPoll($this->poll());

        $this->mpSays('approved', $order);
        $this->nextPollWindow();

        $this->poll()->assertExactJson(['status' => 'paid']);
        $this->assertSame(1, $this->events());
    }

    public function test_an_unexpected_exception_while_reconciling_is_logged_and_swallowed(): void
    {
        $order = $this->createPix();
        $logs = $this->captureLogs();
        Http::fake(fn () => throw new \RuntimeException('falha inesperada com TEST-access-token-mp-loja'));

        $this->assertPendingPoll($this->poll()->assertOk());

        $entries = $this->logsNamed($logs, 'payment.reconcile.failed');
        $this->assertCount(1, $entries);
        $this->assertSame(\RuntimeException::class, $entries[0]['context']['exception']);
        $this->assertStringNotContainsString('TEST-access-token', (string) json_encode($entries), 'só a classe da exceção vai ao log');
        $this->assertSame($order->id, $entries[0]['context']['order_id']);
    }

    public function test_a_broken_cache_does_not_break_the_endpoint(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);
        $logs = $this->captureLogs();
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache fora'));

        $this->assertPendingPoll($this->poll()->assertOk());

        $this->assertCount(1, $this->logsNamed($logs, 'payment.reconcile.failed'));
        $this->assertSame(0, $this->mpLookups(), 'sem throttle confiável, não bate no MP');
    }

    // ------------------------------------------------------------------ throttle

    public function test_two_consecutive_polls_make_a_single_call_to_mercado_pago(): void
    {
        $order = $this->createPix();
        $this->mpSays('pending', $order);

        $this->assertPendingPoll($this->poll());
        $this->assertPendingPoll($this->poll());
        $this->assertPendingPoll($this->poll());

        $this->assertSame(1, $this->mpLookups());
    }

    public function test_the_throttle_window_is_about_three_seconds(): void
    {
        $this->assertSame(3, PaymentReconciler::THROTTLE_SECONDS);
    }

    public function test_after_the_window_the_gateway_is_asked_again(): void
    {
        $order = $this->createPix();
        $this->mpSays('pending', $order);

        $this->poll();
        $this->nextPollWindow();
        $this->poll();

        $this->assertSame(2, $this->mpLookups());
    }

    public function test_the_throttle_is_per_payment_not_global(): void
    {
        $orderA = $this->createPix();
        $this->mpSays('pending', $orderA);
        $this->poll();

        // outra loja, outro pagamento: não compete pela vaga da primeira. O contexto da requisição
        // anterior não vaza para o cadastro da loja nova (em produção cada requisição tem o seu).
        app(\App\Support\TenantContext::class)->forget();
        $other = $this->mpTenant('outra-loja');
        $productB = $this->product($other, 1000, stock: 5);
        $this->fakeMpOrderCreation('ORDTEST888002', 'pending');
        $this->withSession($this->checkoutSession($other, $productB, 1))
            ->postJson('/outra-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();
        $orderB = Order::withoutTenantScope()->where('tenant_id', $other->id)->sole();
        $this->fakeMpPaymentLookup('ORDTEST888002', 'pending', $orderB);

        $this->assertPendingPoll($this->getJson('/outra-loja/checkout/status'));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/v1/orders/ORDTEST888002'));
    }

    // ------------------------------------------------------------------ o que NÃO consulta

    public function test_a_decided_order_is_never_looked_up(): void
    {
        $order = $this->createPix();
        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        $this->mpSays('approved', $order);

        $this->poll()->assertExactJson(['status' => 'cancelled']);

        $this->assertSame(0, $this->mpLookups());
    }

    public function test_a_payment_without_gateway_id_is_not_looked_up(): void
    {
        $order = $this->createPix();
        Payment::withoutTenantScope()->where('order_id', $order->id)->update(['gateway_payment_id' => null, 'status' => 'created']);
        $this->mpSays('approved', $order);

        $this->assertPendingPoll($this->poll());

        $this->assertSame(0, $this->mpLookups());
    }

    public function test_a_wrong_session_or_tenant_still_gets_404_and_never_reaches_the_gateway(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);
        $other = $this->mpTenant('outra-loja');

        // mesma sessão, path de OUTRO tenant
        $this->poll('outra-loja')->assertNotFound()->assertExactJson(['message' => 'not_found']);

        // sessão forjada do tenant B apontando para o pedido do tenant A
        $this->flushSession();
        $this->withSession(['checkout.order.'.$other->id => $order->id])->getJson('/outra-loja/checkout/status')->assertNotFound();

        // outro navegador
        $this->flushSession();
        $this->poll()->assertNotFound()->assertExactJson(['message' => 'not_found']);

        $this->assertSame(0, $this->mpLookups());
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
    }

    public function test_the_response_is_still_only_the_status(): void
    {
        $order = $this->createPix();
        $this->mpSays('approved', $order);

        $response = $this->poll()->assertOk();

        $this->assertSame(['status' => 'paid'], $response->json());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach (['point_of_interaction', 'qr_code', self::PAYMENT_ID, $order->id, 'TEST-access-token'] as $leak) {
            $this->assertStringNotContainsString((string) $leak, (string) $response->getContent());
        }
    }

    public function test_the_external_reference_of_another_order_is_rejected_by_the_shared_path(): void
    {
        // O MESMO guard do webhook: o MP diz (pelo `external_reference`, o vínculo de Orders) que a
        // order é de OUTRO pedido => nada muda.
        $order = $this->createPix();
        $this->mpSays('approved', $order, ['external_reference' => '00000000-0000-0000-0000-000000000000']);

        $this->assertPendingPoll($this->poll());

        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
        $this->assertSame(0, $this->events());
    }
}

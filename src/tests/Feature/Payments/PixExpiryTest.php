<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentCancellation;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Gateways\PaymentGatewayManager;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use App\Services\PaymentReconciler;
use App\Support\PixWindow;
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
use Tests\Support\Gateways\FakeGateway;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * Janela de pagamento do Pix (5 min): o SERVIDOR a impõe. Vencida (+ tolerância), consulta a order no
 * MP, respeita um Pix já pago, cancela a order no MP e SÓ DEPOIS cancela o pedido e devolve o estoque.
 *
 * Tudo com `Http::fake`, no formato real de Orders (`MercadoPagoOrders`); nada chama o MP.
 */
class PixExpiryTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const ID = 'ORDTEST777001';

    private const CANCEL_URL = 'api.mercadopago.com/v1/orders/'.self::ID.'/cancel';

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

    // ------------------------------------------------------------------ apoio

    /** O navegador cria o Pix (pedido pendente; estoque 5 → 3); devolve a resposta de `process_payment`. */
    private function createPix(): TestResponse
    {
        $this->fakeMpOrderCreation(self::ID, 'pending');

        return $this
            ->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();
    }

    private function order(): Order
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function payment(): Payment
    {
        return Payment::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }

    private function events(): int
    {
        return DB::table('payment_webhook_events')->where('gateway_payment_id', self::ID)->count();
    }

    private function poll(): TestResponse
    {
        return $this->getJson('/mp-loja/checkout/status');
    }

    /** Anda o relógio até DEPOIS da janela + tolerância (o primeiro instante em que o servidor cancela). */
    private function pastTheWindow(): void
    {
        $this->travel(PixWindow::seconds() + PixWindow::GRACE_SECONDS + 1)->seconds();
    }

    private function nextCycle(): void
    {
        $this->travel(PaymentReconciler::THROTTLE_SECONDS + 1)->seconds();
    }

    private function mpOrder(string $status): array
    {
        return MercadoPagoOrders::body(self::ID, $status, $this->order());
    }

    /**
     * Simula o MP durante a expiração: `GET /orders/{id}` responde a sequência (a última se repete) e
     * `POST .../cancel` responde `$cancel`.
     *
     * @param  list<string>  $lookups  estados sucessivos da order (`pending`, `cancelled`, `approved`...)
     */
    private function mpExpiry(array $lookups, mixed $cancel): void
    {
        Http::swap(new HttpFactory);

        $sequence = Http::sequence();
        foreach ($lookups as $status) {
            $sequence->push($this->mpOrder($status), 200);
        }
        $sequence->whenEmpty(Http::response($this->mpOrder(end($lookups)), 200));

        Http::fake([
            self::CANCEL_URL => $cancel,
            'api.mercadopago.com/v1/orders/'.self::ID => $sequence,
        ]);
    }

    private function cancelOk(): mixed
    {
        return Http::response(MercadoPagoOrders::body(self::ID, 'cancelled'), 200);
    }

    private function cancelCalls(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/orders/'.self::ID.'/cancel'))->count();
    }

    // ------------------------------------------------------------------ o prazo é gravado no servidor

    public function test_accepting_the_pix_records_the_absolute_deadline_and_returns_it_with_the_server_clock(): void
    {
        $this->travelTo('2026-10-23 12:00:00');

        $response = $this->createPix();

        $expected = '2026-10-23T12:05:00Z';
        $this->assertSame($expected, $this->payment()->meta['pix_expires_at']);
        $this->assertSame($expected, $response->json('expires_at'));
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $response->json('server_now'));

        // O prazo de 30 min enviado ao MP é outro assunto (teto de segurança do lado dele).
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/v1/orders')
            && data_get($r->data(), 'transactions.payments.0.expiration_time') === 'PT30M');
    }

    public function test_the_window_follows_the_config_with_the_ceiling_and_never_changes_the_mp_expiration_time(): void
    {
        config(['services.mercadopago.pix_window' => 120]);
        $this->travelTo('2026-10-23 12:00:00');
        $this->createPix();
        $this->assertSame('2026-10-23T12:02:00Z', $this->payment()->meta['pix_expires_at']);
    }

    public function test_the_deadline_is_rounded_up_so_the_customer_never_gets_less_than_the_window(): void
    {
        $this->travelTo('2026-10-23 12:00:00.400');
        $this->createPix();

        $this->assertSame('2026-10-23T12:05:01Z', $this->payment()->meta['pix_expires_at']);
    }

    public function test_a_configured_window_above_the_ceiling_is_recorded_as_five_minutes(): void
    {
        config(['services.mercadopago.pix_window' => 600]);
        $this->travelTo('2026-10-23 12:00:00');
        $this->createPix();

        $this->assertSame('2026-10-23T12:05:00Z', $this->payment()->meta['pix_expires_at']);
        Http::assertSent(fn (Request $r) => data_get($r->data(), 'transactions.payments.0.expiration_time') === 'PT30M');
    }

    public function test_a_card_payment_gets_no_pix_window(): void
    {
        $this->fakeMpOrderCreation(self::ID, 'in_process');

        $response = $this
            ->withSession($this->checkoutSession($this->tenant, $this->product, 1))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody([
                'mp_method' => 'mp_credit_card',
                'formData' => ['token' => 'card-token', 'payment_method_id' => 'master'],
            ]))
            ->assertOk();

        $this->assertArrayNotHasKey('pix_expires_at', $this->payment()->meta ?? []);
        $this->assertArrayNotHasKey('expires_at', $response->json());
        $this->assertArrayNotHasKey('server_now', $response->json());
    }

    public function test_the_status_returns_the_stored_deadline_so_a_reload_never_resets_the_countdown(): void
    {
        $this->travelTo('2026-10-23 12:00:00');
        $created = $this->createPix()->json('expires_at');

        $this->travel(90)->seconds();
        $this->fakeMpPaymentLookup(self::ID, 'pending', $this->order());

        $json = $this->poll()->assertOk()->json();

        $this->assertSame($created, $json['expires_at'], 'o prazo não pode "voltar": é o gravado');
        $this->assertSame(now()->utc()->format('Y-m-d\TH:i:s.v\Z'), $json['server_now']);
    }

    // ------------------------------------------------------------------ dentro da janela e limites

    public function test_nothing_is_cancelled_inside_the_window_nor_inside_the_grace_but_it_is_right_after(): void
    {
        $this->travelTo('2026-10-23 12:00:00.400');
        $this->createPix();

        // t = janela − 1 s: o cliente ainda pode pagar.
        $this->travel(PixWindow::seconds() - 1)->seconds();
        $this->mpExpiry(['pending'], $this->cancelOk());
        $this->assertPendingPoll($this->poll()->assertOk());
        $this->assertSame(0, $this->cancelCalls());
        $this->assertSame(OrderStatus::PENDING, $this->order()->status);
        $this->assertSame(3, $this->stock());

        // t = janela + tolerância (exato): ainda não.
        Cache::flush(); // o throttle de 3 s por pagamento não é o assunto aqui
        $this->travelTo(now()->addSeconds(1 + PixWindow::GRACE_SECONDS));
        $this->mpExpiry(['pending'], $this->cancelOk());
        $this->assertPendingPoll($this->poll()->assertOk());
        $this->assertSame(0, $this->cancelCalls(), 'a tolerância é do cliente: não se cancela antes dela acabar');
        $this->assertSame(3, $this->stock());

        // t = janela + tolerância + 1 s: cancela.
        Cache::flush();
        $this->travelTo(now()->addSeconds(1));
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());
        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame(1, $this->cancelCalls());
        $this->assertSame(OrderStatus::CANCELLED, $this->order()->status);
        $this->assertSame(5, $this->stock());
    }

    // ------------------------------------------------------------------ vencido

    public function test_an_expired_pix_still_waiting_is_cancelled_at_mercado_pago_then_locally_and_the_stock_returns_once(): void
    {
        $this->createPix();
        $this->assertSame(3, $this->stock());
        $this->pastTheWindow();
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());

        $response = $this->poll()->assertOk();

        $response->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // O cancelamento no MP: POST /v1/orders/{id}/cancel, sem corpo, com chave de idempotência estável.
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://api.mercadopago.com/v1/orders/'.self::ID.'/cancel'
                && $r->hasHeader('X-Idempotency-Key', 'pix-expiry:'.$this->payment()->id)
                && $r->body() === ''
                && $r->hasHeader('Authorization');
        });
        $this->assertSame(1, $this->cancelCalls());

        $order = $this->order();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame('cancelled', $this->payment()->status);
        $this->assertSame(5, $this->stock(), 'estoque devolvido');
        $this->assertSame(1, $this->events());

        // Consultas seguintes: pedido decidido, nada mais é chamado e o estoque não volta de novo.
        $calls = Http::recorded()->count();
        $this->nextCycle();
        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame($calls, Http::recorded()->count());
        $this->assertSame(5, $this->stock());
        $this->assertSame(1, $this->events());
    }

    public function test_the_local_order_is_only_cancelled_after_mercado_pago_confirms(): void
    {
        $this->createPix();
        $this->pastTheWindow();

        // Na hora do cancelamento no MP o pedido local AINDA está pendente e com o estoque baixado.
        $seen = [];
        Http::swap(new HttpFactory);
        Http::fake([
            self::CANCEL_URL => function () use (&$seen) {
                $seen = [$this->order()->status, $this->stock()];

                return $this->cancelOk();
            },
            'api.mercadopago.com/v1/orders/'.self::ID => Http::sequence()
                ->push($this->mpOrder('pending'))
                ->push($this->mpOrder('cancelled')),
        ]);

        $this->poll()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);

        $this->assertSame([OrderStatus::PENDING, 3], $seen);
        $this->assertSame(5, $this->stock());
    }

    public function test_an_expired_pix_that_mercado_pago_already_shows_paid_is_paid_and_never_cancelled(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $this->mpExpiry(['approved'], $this->cancelOk());

        $this->poll()->assertOk()->assertExactJson(['status' => 'paid']);

        $this->assertSame(0, $this->cancelCalls(), 'nunca se cancela um Pix já pago');
        $order = $this->order();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(3, $this->stock());
        $this->assertSame('approved', $this->payment()->status);
        $this->assertSame(1, $this->events());
    }

    public function test_a_cancel_conflict_because_it_was_paid_at_the_last_instant_respects_the_paid_state(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        // 1ª consulta: ainda aguardando; o cancel dá 409 (pago agora); a reconsulta mostra pago.
        $this->mpExpiry(['pending', 'approved'], Http::response(MercadoPagoOrders::errorBody('cannot_cancel_order', 'cannot cancel'), 409));

        $this->poll()->assertOk()->assertExactJson(['status' => 'paid']);

        $this->assertSame(1, $this->cancelCalls());
        $order = $this->order();
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
        $this->assertNotSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(3, $this->stock(), 'pedido pago não devolve estoque');
    }

    public function test_an_already_canceled_conflict_cancels_the_local_order_exactly_once(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $this->mpExpiry(['pending', 'cancelled'], Http::response(MercadoPagoOrders::errorBody('order_already_canceled', 'already'), 409));

        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);

        $this->assertSame(OrderStatus::CANCELLED, $this->order()->status);
        $this->assertSame(5, $this->stock());
        $this->assertSame(1, $this->events());

        $this->nextCycle();
        $this->poll()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame(5, $this->stock());
    }

    /** @return array<string, array{0: callable(): mixed}> */
    public static function cancelFailures(): array
    {
        return [
            'timeout/rede' => [fn () => fn () => throw new ConnectionException('cURL error 28: timeout')],
            'erro 500' => [fn () => Http::response(['message' => 'internal'], 500)],
            'erro 503' => [fn () => Http::response('', 503)],
            'credencial recusada' => [fn () => Http::response(MercadoPagoOrders::errorBody('invalid_credentials'), 401)],
        ];
    }

    #[DataProvider('cancelFailures')]
    public function test_a_cancel_that_fails_leaves_the_order_pending_and_the_stock_untouched_and_the_next_round_finishes(callable $failure): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $logs = $this->captureLogs();
        $this->mpExpiry(['pending'], $failure());

        $this->assertPendingPoll($this->poll()->assertOk());

        $order = $this->order();
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame(3, $this->stock(), 'sem confirmação do MP não se devolve estoque');
        $this->assertSame(0, $this->events());

        $entries = $this->logsNamed($logs, 'payment.pix_expiry.cancel_failed');
        $this->assertCount(1, $entries);
        $this->assertSame($this->tenant->id, $entries[0]['context']['tenant_id']);
        $this->assertSame($this->payment()->id, $entries[0]['context']['payment_id']);
        $this->assertStringNotContainsString('TEST-access-token', json_encode($entries[0]['context']));

        // Antes de passar o throttle de 3 s o MP nem é consultado de novo.
        $calls = Http::recorded()->count();
        $this->poll()->assertOk();
        $this->assertSame($calls, Http::recorded()->count());

        // Próximo ciclo: o MP voltou; cancela e conclui.
        $this->nextCycle();
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());
        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame(OrderStatus::CANCELLED, $this->order()->status);
        $this->assertSame(5, $this->stock());
        $this->assertSame(1, $this->events());
    }

    public function test_when_the_lookup_itself_fails_nothing_is_cancelled_anywhere(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $logs = $this->captureLogs();
        Http::swap(new HttpFactory);
        Http::fake([
            self::CANCEL_URL => $this->cancelOk(),
            'api.mercadopago.com/v1/orders/'.self::ID => Http::response('', 500),
        ]);

        $this->assertPendingPoll($this->poll()->assertOk());

        $this->assertSame(0, $this->cancelCalls());
        $this->assertSame(OrderStatus::PENDING, $this->order()->status);
        $this->assertSame(3, $this->stock());
        $this->assertNotEmpty($this->logsNamed($logs, 'payment.pix_expiry.lookup_failed'));
    }

    public function test_an_order_mercado_pago_still_processes_is_not_cancelled_it_stays_pending_and_is_retried(): void
    {
        // `processing/in_process` (a order de Pix sem QR): o MP só cancela created/action_required.
        $this->createPix();
        $this->pastTheWindow();
        $logs = $this->captureLogs();
        $this->mpExpiry(['in_process'], $this->cancelOk());

        $this->assertPendingPoll($this->poll()->assertOk());

        $this->assertSame(0, $this->cancelCalls());
        $this->assertSame(OrderStatus::PENDING, $this->order()->status);
        $this->assertSame(3, $this->stock());
        $this->assertNotEmpty($this->logsNamed($logs, 'payment.pix_expiry.not_cancelable_state'));
    }

    public function test_a_cancel_the_gateway_accepts_but_does_not_show_yet_stays_pending_until_it_does(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $logs = $this->captureLogs();
        $this->mpExpiry(['pending', 'pending'], $this->cancelOk());

        $this->assertPendingPoll($this->poll()->assertOk());
        $this->assertSame(3, $this->stock());
        $this->assertNotEmpty($this->logsNamed($logs, 'payment.pix_expiry.still_open'));

        $this->nextCycle();
        $this->mpExpiry(['cancelled'], $this->cancelOk());
        $this->poll()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame(5, $this->stock());
        $this->assertSame(0, $this->cancelCalls(), 'já cancelado no MP: nada mais a cancelar');
    }

    public function test_a_late_webhook_after_the_expiry_is_a_no_op(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());
        $this->poll()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);

        $this->fakeMpPaymentLookup(self::ID, 'cancelled', $this->order());
        $response = $this->postMpWebhook($this->tenant, self::ID, $this->mpSignedHeaders(self::ID))->assertOk();

        $this->assertNotTrue($response->json('transitioned'));
        $this->assertSame(5, $this->stock());
        $this->assertSame(1, $this->events());
    }

    public function test_a_stale_waiting_lookup_never_moves_a_decided_payment_back_to_open(): void
    {
        // Duas verificações concorrentes: a lenta consultou o MP ANTES do cancelamento e aplica o
        // "aguardando" DEPOIS dele. O pagamento cancelado não pode voltar a `pending`.
        $this->createPix();
        $this->pastTheWindow();
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());
        $this->poll()->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);
        $this->assertSame(1, $this->events());

        $stale = \App\Contracts\PaymentResult::success(self::ID, 'pending', 'waiting_transfer', ['metadata' => ['order_id' => $this->order()->id]]);
        $result = app(\App\Services\PaymentWebhookProcessor::class)->reconcileWithRemote(
            app(PaymentGatewayManager::class)->forProvider('mercadopago'),
            $this->tenant,
            self::ID,
            $stale
        );

        $this->assertFalse($result->transitioned);
        $this->assertSame('cancelled', $this->payment()->status);
        $this->assertSame(OrderStatus::CANCELLED, $this->order()->status);
        $this->assertSame(1, $this->events());
        $this->assertSame(5, $this->stock());
    }

    // ------------------------------------------------------------------ o que o endpoint expõe

    public function test_the_expired_response_carries_only_status_and_reason_and_only_for_the_owner_session(): void
    {
        $this->createPix();
        $this->pastTheWindow();
        $this->mpExpiry(['pending', 'cancelled'], $this->cancelOk());

        $json = $this->poll()->assertOk()->json();
        $this->assertSame(['reason', 'status'], collect($json)->keys()->sort()->values()->all());

        // Outro navegador (sem a sessão do pedido): 404 idêntico, sem consultar o MP.
        $this->flushSession();
        Http::swap(new HttpFactory);
        Http::fake();
        $this->poll()->assertNotFound()->assertExactJson(['message' => 'not_found']);
        $this->getJson('/outra-loja/checkout/status')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_an_order_cancelled_for_another_reason_is_not_reported_as_expired(): void
    {
        $this->createPix();
        $this->fakeMpPaymentLookup(self::ID, 'pending', $this->order());
        // O lojista cancela o pedido (a cobrança segue pendente): cancelado, mas NÃO "expirado".
        app(OrderService::class)->updateOrder($this->order(), OrderStatus::CANCELLED, PaymentStatus::UNPAID, 'mp_pix');

        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled']);
    }

    // ------------------------------------------------------------------ outro gateway

    public function test_the_expiry_is_gateway_agnostic_a_gateway_with_the_capability_is_cancelled_through_the_interface(): void
    {
        $this->app->bind(MercadoPagoGateway::class, fn () => $this->fail('instanciou o MercadoPagoGateway'));
        Http::preventStrayRequests();

        $fake = new FakeGateway;
        app(PaymentGatewayManager::class)->extend('fake', fn () => $fake);

        $tenant = Tenant::factory()->create([
            'slug' => 'fake-loja',
            'is_active' => true,
            'settings' => ['payment' => [
                'fake' => ['secret' => 'fake-secret'],
                'methods' => [['id' => 'mp_pix', 'provider' => 'fake', 'label' => 'PIX', 'enabled' => true]],
            ]],
        ]);
        $product = $this->product($tenant, 1000, stock: 5);

        $this->withSession($this->checkoutSession($tenant, $product, 2))
            ->postJson('/fake-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk()
            ->assertJsonStructure(['expires_at', 'server_now']);

        $this->pastTheWindow();
        $this->getJson('/fake-loja/checkout/status')->assertExactJson(['status' => 'cancelled', 'reason' => 'expired']);

        $this->assertCount(1, $fake->cancellations);
        $this->assertSame('fake-1', $fake->cancellations[0]['payment_id']);
        $this->assertSame(5, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // Cancelamento indisponível no gateway: nada local muda.
        $fake->cancelOutcome = PaymentCancellation::UNAVAILABLE;
        $second = $this->product($tenant, 1000, stock: 4);
        $this->flushSession();
        $this->withSession($this->checkoutSession($tenant, $second, 1, 'outro-nonce'))
            ->postJson('/fake-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();
        $this->pastTheWindow();
        $this->getJson('/fake-loja/checkout/status')->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(3, (int) DB::table('products')->where('id', $second->id)->value('stock_quantity'));
    }
}

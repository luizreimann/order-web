<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * `GET /{tenant}/checkout/status`: o polling da tela de PIX.
 *
 * Segurança: só responde ao navegador (SESSÃO) que criou o pedido, no tenant do path; a
 * resposta é só o estado. Em testes, a sessão `array` é compartilhada pelas requisições do
 * teste: `flushSession()` simula "outro navegador".
 */
class CheckoutStatusEndpointTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
        $this->product = $this->product($this->tenant, 1000, stock: 5);
        RateLimiter::clear('checkout-status');
    }

    /** O navegador A cria um PIX (pedido pendente, estoque baixado). */
    private function createPixAsBrowserA(): Order
    {
        $this->fakeMpOrderCreation('ORDTEST777001', 'pending');

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

    // ------------------------------------------------------------------ o que responde

    public function test_the_browser_that_created_the_order_gets_only_the_status_and_the_pix_of_a_pending_order(): void
    {
        $this->createPixAsBrowserA();

        $response = $this->poll()->assertOk();

        // Resposta mínima: `{status}` e, enquanto o pedido está PENDENTE, só o QR do Pix (três campos);
        // nada de id da order, número, valor, cliente nem o corpo do gateway.
        $this->assertPendingPoll($response);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_decided_order_never_carries_the_pix_in_the_response(): void
    {
        $order = $this->createPixAsBrowserA();
        $this->fakeMpPaymentLookup('ORDTEST777001', 'approved', $order);

        $this->poll()->assertOk()->assertExactJson(['status' => 'paid']);
    }

    public function test_a_pix_without_a_qr_yet_returns_only_the_status_and_the_qr_arrives_with_the_reconciliation(): void
    {
        // A order de Orders nasce `processing`/`in_process` SEM QR (observado na sandbox).
        $this->fakeMpOrderCreation('ORDTEST777001', 'in_process');
        $this->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk()
            ->assertJsonPath('point_of_interaction', null)
            ->assertJsonPath('status', 'in_process');
        $order = Order::withoutTenantScope()->sole();

        // Ainda sem QR no MP: só o estado e os dois instantes do contador.
        $json = $this->poll()->assertOk()->json();
        $this->assertSame(['expires_at', 'server_now', 'status'], collect($json)->keys()->sort()->values()->all());
        $this->assertSame('pending', $json['status']);

        // O MP passa a `action_required/waiting_transfer` com o QR: o próximo polling o entrega.
        $this->travel(PaymentReconciler::THROTTLE_SECONDS + 1)->seconds();
        $this->fakeMpPaymentLookup('ORDTEST777001', 'pending', $order);
        $this->assertPendingPoll($this->poll()->assertOk());

        // E fica guardado: uma consulta posterior que não o traga não o apaga.
        $this->travel(PaymentReconciler::THROTTLE_SECONDS + 1)->seconds();
        $this->fakeMpPaymentLookup('ORDTEST777001', 'pending', $order, ['transactions' => ['payments' => [['payment_method' => ['qr_code' => '', 'qr_code_base64' => '', 'ticket_url' => '']]]]]);
        $this->assertPendingPoll($this->poll()->assertOk());
    }

    public function test_a_replayed_double_submit_keeps_the_order_reachable_by_the_same_browser(): void
    {
        $this->createPixAsBrowserA();
        $this->withSession($this->checkoutSession($this->tenant, $this->product, 2))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertOk();

        $this->assertPendingPoll($this->poll()->assertOk());
    }

    // ------------------------------------------------------------------ quem NÃO pode consultar

    public function test_another_browser_session_cannot_query_the_order(): void
    {
        $order = $this->createPixAsBrowserA();
        $this->poll()->assertOk();

        $this->flushSession(); // outro navegador: nenhuma sessão do cliente A

        $other = $this->poll()->assertNotFound();
        $this->assertStringNotContainsString($order->id, (string) $other->getContent());
        $this->assertSame(['message' => 'not_found'], $other->json());
    }

    public function test_a_browser_that_never_paid_online_gets_the_same_not_found(): void
    {
        $this->createPixAsBrowserA();
        $existing = $this->poll()->assertOk();
        $this->flushSession();

        $this->poll()->assertNotFound()->assertExactJson(['message' => 'not_found']);
        $this->assertSame(200, $existing->status());
    }

    public function test_another_tenant_path_does_not_answer_with_this_tenants_order(): void
    {
        $this->createPixAsBrowserA();
        $this->mpTenant('outra-loja');

        // Mesma sessão (o navegador A), mas o path é de OUTRO tenant: a chave da sessão é por tenant.
        $this->poll('outra-loja')->assertNotFound();
        $this->poll('mp-loja')->assertOk();
    }

    public function test_a_forged_session_pointing_to_an_order_of_another_tenant_is_not_found(): void
    {
        $order = $this->createPixAsBrowserA();
        $other = $this->mpTenant('outra-loja');
        $this->flushSession();

        // Cenário extremo: a sessão do tenant B aponta para um pedido do tenant A.
        $this->withSession(['checkout.order.'.$other->id => $order->id])
            ->getJson('/outra-loja/checkout/status')
            ->assertNotFound();
    }

    public function test_an_unknown_order_id_in_the_session_is_not_found(): void
    {
        $this->withSession(['checkout.order.'.$this->tenant->id => '00000000-0000-0000-0000-000000000000'])
            ->getJson('/mp-loja/checkout/status')
            ->assertNotFound();
    }

    public function test_a_declined_online_payment_leaves_nothing_to_poll(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::errorBody('property_value', 'Invalid value for property'), 400)]);

        $this->withSession($this->checkoutSession($this->tenant, $this->product, 1))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))
            ->assertStatus(422);

        $this->poll()->assertNotFound();
    }

    // ------------------------------------------------------------------ transições refletidas

    public function test_the_webhook_approval_turns_the_status_into_paid(): void
    {
        $order = $this->createPixAsBrowserA();
        $this->assertPendingPoll($this->poll());

        $this->fakeMpPaymentLookup('ORDTEST777001', 'approved', $order);
        $this->postMpWebhook($this->tenant, 'ORDTEST777001', $this->mpSignedHeaders('ORDTEST777001'))->assertOk();

        $this->poll()->assertOk()->assertExactJson(['status' => 'paid']);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
    }

    public function test_an_expired_or_cancelled_pix_turns_the_status_into_cancelled_and_returns_the_stock(): void
    {
        $order = $this->createPixAsBrowserA();
        $this->assertSame(3, $this->stockOf());

        $this->fakeMpPaymentLookup('ORDTEST777001', 'cancelled', $order);
        $this->postMpWebhook($this->tenant, 'ORDTEST777001', $this->mpSignedHeaders('ORDTEST777001'))->assertOk();

        $this->poll()->assertOk()->assertExactJson(['status' => 'cancelled']);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()?->status);
        $this->assertSame(5, $this->stockOf());
    }

    public function test_a_payment_received_for_an_already_cancelled_order_is_review_never_a_retry_prompt(): void
    {
        $order = $this->createPixAsBrowserA();
        $order->forceFill(['status' => OrderStatus::CANCELLED, 'payment_status' => PaymentStatus::PAID])->save();

        // "tentar de novo" faria o cliente pagar duas vezes: o estado é outro.
        $this->poll()->assertOk()->assertExactJson(['status' => 'review']);
    }

    // ------------------------------------------------------------------ rate limit

    public function test_the_limiter_buckets_by_browser_session_first_and_by_ip_as_a_loose_ceiling(): void
    {
        $limiter = RateLimiter::limiter('checkout-status');
        $this->assertNotNull($limiter);

        $request = function (string $sessionId, string $ip): Request {
            $request = Request::create('/mp-loja/checkout/status', 'GET', server: ['REMOTE_ADDR' => $ip]);
            $store = app('session')->driver();
            $store->setId($sessionId);
            $request->setLaravelSession($store);

            return $request;
        };

        $a = $limiter($request(str_repeat('a', 40), '10.0.0.1'));
        $sameBrowser = $limiter($request(str_repeat('a', 40), '10.0.0.1'));
        $otherBrowserSameNat = $limiter($request(str_repeat('b', 40), '10.0.0.1'));

        $this->assertCount(2, $a);
        $this->assertSame(40, $a[0]->maxAttempts, 'por sessão: ~15 req/min de uma aba com folga');
        $this->assertSame(300, $a[1]->maxAttempts, 'teto por IP, folgado para NAT');
        $this->assertSame($a[0]->key, $sameBrowser[0]->key);
        $this->assertNotSame($a[0]->key, $otherBrowserSameNat[0]->key, 'dois clientes no mesmo NAT não competem');
        $this->assertSame($a[1]->key, $otherBrowserSameNat[1]->key);
    }

    public function test_the_endpoint_answers_429_with_retry_after_when_a_client_hammers_it(): void
    {
        // Em testes cada requisição ganha um id de sessão novo, então só o teto por IP enche.
        for ($i = 0; $i < 300; $i++) {
            $this->poll()->assertNotFound();
        }

        $limited = $this->poll()->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
    }

    public function test_the_status_route_uses_the_throttle_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('tenant.checkout.status');

        $this->assertNotNull($route);
        $this->assertContains('throttle:checkout-status', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }
}

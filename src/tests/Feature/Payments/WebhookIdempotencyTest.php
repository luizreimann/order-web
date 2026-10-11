<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * E3.3 — webhook autenticado ANTES de qualquer consulta, e idempotente.
 *
 * Estratégia: a `notification_url` enviada ao criar o pagamento carrega o id do
 * tenant (`/webhooks/{gateway}/{tenantId}`), então o `webhook_secret` certo é
 * conhecido SEM olhar o payload nem tocar pedidos/pagamentos.
 */
class WebhookIdempotencyTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    /** @return list<string> SQL executado durante o callback */
    private function sqlDuring(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    // ---------------------------------------------------------- assinatura primeiro

    public function test_invalid_signature_is_rejected_even_when_the_order_does_not_exist_and_nothing_is_consulted(): void
    {
        $tenant = $this->mpTenant();
        Http::fake();

        $sql = $this->sqlDuring(function () use ($tenant) {
            $this->postMpWebhook($tenant, '777000111', [
                'x-signature' => 'ts=1700000000,v1=deadbeef',
                'x-request-id' => 'req-1',
            ])->assertStatus(401);
        });

        Http::assertNothingSent();
        $touched = array_values(array_filter($sql, fn ($s) => preg_match('/"(orders|payments|payment_webhook_events)"/', $s)));
        $this->assertSame([], $touched, 'o webhook sem assinatura válida consultou pedidos/pagamentos');
    }

    public function test_missing_signature_header_is_rejected_when_the_tenant_has_a_secret(): void
    {
        $tenant = $this->mpTenant();
        Http::fake();

        $this->postMpWebhook($tenant, '777000112', [])->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_signature_made_with_another_tenants_secret_is_rejected(): void
    {
        $a = $this->mpTenant('loja-a', 'secret-a');
        $this->mpTenant('loja-b', 'secret-b');
        Http::fake();

        $this->postMpWebhook($a, '777000113', $this->mpSignedHeaders('777000113', 'secret-b'))->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_unknown_tenant_in_the_url_is_rejected_without_consulting_anything(): void
    {
        $this->mpTenant();
        Http::fake();

        $this->postJson('/webhooks/mercadopago/'.\Illuminate\Support\Str::uuid(), [
            'type' => 'payment', 'data' => ['id' => '1'],
        ], $this->mpSignedHeaders('1'))->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_unknown_gateway_is_404_on_both_routes(): void
    {
        $tenant = $this->mpTenant();

        $this->postJson('/webhooks/inexistente', ['id' => 1])->assertNotFound();
        $this->postJson('/webhooks/inexistente/'.$tenant->id, ['id' => 1])->assertNotFound();
    }

    public function test_valid_signature_for_an_unknown_payment_is_a_200_ignore_so_the_gateway_stops_retrying(): void
    {
        $tenant = $this->mpTenant();
        // O MP conhece o pagamento mas o metadata aponta para um pedido que não existe.
        Http::fake([
            'api.mercadopago.com/v1/payments/888000001' => Http::response([
                'id' => 888000001, 'status' => 'approved',
                'metadata' => ['tenant_id' => $tenant->id, 'order_id' => (string) \Illuminate\Support\Str::uuid()],
            ], 200),
        ]);

        $this->postMpWebhook($tenant, '888000001', $this->mpSignedHeaders('888000001'))
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    // ---------------------------------------------------------- fail closed / bypass

    public function test_without_a_configured_secret_production_fails_closed(): void
    {
        $tenant = $this->mpTenant('sem-secret', withSecret: false);
        [$order] = $this->orderWithMpPayment($tenant, '777000201');
        $this->fakeMpPaymentLookup('777000201', 'approved', $order);
        $this->app['env'] = 'production';

        $this->postMpWebhook($tenant, '777000201', $this->mpSignedHeaders('777000201', 'qualquer'))->assertStatus(401);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_without_a_configured_secret_the_bypass_exists_only_in_local_and_testing(): void
    {
        $gateway = new MercadoPagoGateway;

        foreach (['local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->assertTrue($gateway->validateWebhookSignature('1', [], null), $env);
        }

        foreach (['production', 'staging', 'development'] as $env) {
            $this->app['env'] = $env;
            $this->assertFalse($gateway->validateWebhookSignature('1', [], null), $env);
        }
    }

    public function test_without_a_secret_testing_still_processes_unsigned_webhooks(): void
    {
        $tenant = $this->mpTenant('sem-secret', withSecret: false);
        [$order] = $this->orderWithMpPayment($tenant, '777000202');
        $this->fakeMpPaymentLookup('777000202', 'approved', $order);

        $this->postMpWebhook($tenant, '777000202', [])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
    }

    // ---------------------------------------------------------- legado: sem tenant na URL

    public function test_tenantless_route_authenticates_with_the_secret_of_the_tenant_that_owns_the_payment(): void
    {
        $tenant = $this->mpTenant('dona', 'secret-dona');
        [$order] = $this->orderWithMpPayment($tenant, '777000301');
        $this->fakeMpPaymentLookup('777000301', 'approved', $order);

        $this->postJson('/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '777000301']], [
            'x-signature' => 'ts=1,v1=invalida', 'x-request-id' => 'r',
        ])->assertStatus(401);
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);

        $this->postJson('/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '777000301']], $this->mpSignedHeaders('777000301', 'secret-dona'))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
    }

    public function test_tenantless_route_in_production_cannot_authenticate_an_unknown_payment_and_fails_closed(): void
    {
        $this->mpTenant();
        $this->app['env'] = 'production';
        Http::fake();

        $this->postJson('/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '999000999']], $this->mpSignedHeaders('999000999'))
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------- idempotência

    public function test_the_same_webhook_delivered_three_times_causes_a_single_transition(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, '777000401');
        $this->fakeMpPaymentLookup('777000401', 'approved', $order);

        $transitions = [];
        foreach (range(1, 3) as $delivery) {
            // O MP reenvia a mesma notificação (mesmo x-request-id).
            $response = $this->postMpWebhook($tenant, '777000401', $this->mpSignedHeaders('777000401', requestId: 'req-fixo'))
                ->assertOk()->assertJson(['ok' => true]);
            $transitions[] = $response->json('transitioned');
        }

        $this->assertSame([true, false, false], $transitions);
        $this->assertSame(1, DB::table('payment_webhook_events')->where('gateway_payment_id', '777000401')->count());
        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
        $this->assertSame('approved', $payment->refresh()->status);
        // Reenvio com o mesmo request-id é cortado ANTES da consulta à API: 1 só GET.
        Http::assertSentCount(1);
    }

    public function test_redelivery_with_a_new_request_id_is_a_no_op_because_the_state_did_not_change(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, '777000402');
        $this->fakeMpPaymentLookup('777000402', 'approved', $order);

        $this->postMpWebhook($tenant, '777000402', $this->mpSignedHeaders('777000402'))->assertJson(['transitioned' => true]);
        $lastWebhookAt = $payment->refresh()->meta['last_webhook_at'] ?? null;
        $this->assertNotNull($lastWebhookAt);

        $this->travel(5)->minutes();

        foreach (range(1, 2) as $_) {
            $this->postMpWebhook($tenant, '777000402', $this->mpSignedHeaders('777000402'))
                ->assertOk()->assertJson(['ok' => true, 'transitioned' => false]);
        }

        $this->assertSame($lastWebhookAt, $payment->refresh()->meta['last_webhook_at'], 'reenvio regravou o pagamento');
        $this->assertSame(1, DB::table('payment_webhook_events')->where('gateway_payment_id', '777000402')->count());
    }

    public function test_each_distinct_status_is_one_transition(): void
    {
        $tenant = $this->mpTenant();
        [$order] = $this->orderWithMpPayment($tenant, '777000403');

        $this->fakeMpPaymentLookup('777000403', 'in_process', $order);
        $this->postMpWebhook($tenant, '777000403', $this->mpSignedHeaders('777000403'))->assertJson(['transitioned' => true]);
        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);

        $this->fakeMpPaymentLookup('777000403', 'approved', $order);
        $this->postMpWebhook($tenant, '777000403', $this->mpSignedHeaders('777000403'))->assertJson(['transitioned' => true]);
        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);

        $this->assertSame(2, DB::table('payment_webhook_events')->where('gateway_payment_id', '777000403')->count());
    }

    public function test_rejected_three_times_cancels_the_pending_order_and_returns_the_stock_once(): void
    {
        $tenant = $this->mpTenant();
        $product = $this->product($tenant, 1000, stock: 5);
        $order = app(\App\Services\OrderService::class)->createFromCart(
            $tenant,
            ['name' => 'Ana', 'email' => 'a@example.com', 'phone' => '11988887777'],
            [['product_id' => $product->id, 'qty' => 2]],
            'mp_pix'
        );
        Payment::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'gateway' => 'mercadopago',
            'gateway_payment_id' => '777000404', 'amount_cents' => $order->total_cents, 'status' => 'pending',
        ]);
        $this->assertSame(3, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->fakeMpPaymentLookup('777000404', 'rejected', $order);

        foreach (range(1, 3) as $_) {
            $this->postMpWebhook($tenant, '777000404', $this->mpSignedHeaders('777000404'))->assertOk();
        }

        $this->assertSame(OrderStatus::CANCELLED, $this->orderStatusOf($order));
        $this->assertSame(5, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'), 'estoque devolvido mais de uma vez (ou nenhuma)');
    }

    // ---------------------------------------------------------- defesa em profundidade

    public function test_the_status_comes_from_the_api_never_from_the_webhook_body(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, '777000501');
        $this->fakeMpPaymentLookup('777000501', 'pending', $order);

        $this->postMpWebhook($tenant, '777000501', $this->mpSignedHeaders('777000501'), [
            'status' => 'approved', 'data' => ['id' => '777000501', 'status' => 'approved'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        $this->assertSame('pending', $payment->refresh()->status);
    }

    public function test_the_api_is_queried_with_the_token_of_the_tenant_in_the_url(): void
    {
        $tenant = $this->mpTenant('dona-token');
        [$order] = $this->orderWithMpPayment($tenant, '777000502');
        $this->fakeMpPaymentLookup('777000502', 'approved', $order);

        $this->postMpWebhook($tenant, '777000502', $this->mpSignedHeaders('777000502'))->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer TEST-access-token-dona-token'));
    }

    public function test_metadata_that_does_not_match_the_order_is_ignored_and_changes_nothing(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, '777000503');
        $other = Order::factory()->create(['tenant_id' => $tenant->id]);
        $this->fakeMpPaymentLookup('777000503', 'approved', $other);

        $this->postMpWebhook($tenant, '777000503', $this->mpSignedHeaders('777000503'))
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public function test_a_payment_of_another_tenant_is_not_touched_through_this_tenants_url(): void
    {
        $a = $this->mpTenant('loja-a', 'secret-a');
        $b = $this->mpTenant('loja-b', 'secret-b');
        [$orderB, $paymentB] = $this->orderWithMpPayment($b, '777000504');
        $this->fakeMpPaymentLookup('777000504', 'approved', $orderB);

        // Assinatura VÁLIDA do tenant A, mas o pagamento é do B.
        $this->postMpWebhook($a, '777000504', $this->mpSignedHeaders('777000504', 'secret-a'))
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame(PaymentStatus::UNPAID, $orderB->refresh()->payment_status);
        $this->assertSame('pending', $paymentB->refresh()->status);
    }

    public function test_a_transient_failure_querying_the_api_is_a_5xx_so_the_gateway_retries(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, '777000505');
        Http::fake(['api.mercadopago.com/v1/payments/777000505' => Http::response([], 503)]);

        $this->postMpWebhook($tenant, '777000505', $this->mpSignedHeaders('777000505'))->assertStatus(503);

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(0, DB::table('payment_webhook_events')->count());

        // A reentrega seguinte (API de volta) processa normalmente.
        $this->fakeMpPaymentLookup('777000505', 'approved', $order);
        $this->postMpWebhook($tenant, '777000505', $this->mpSignedHeaders('777000505'))->assertOk()->assertJson(['transitioned' => true]);
    }

    public function test_unsupported_notification_types_are_a_200_ignore(): void
    {
        $tenant = $this->mpTenant();
        Http::fake();

        $this->postJson('/webhooks/mercadopago/'.$tenant->id, ['type' => 'merchant_order', 'data' => ['id' => '5']], $this->mpSignedHeaders('5'))
            ->assertOk()->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------- reconciliação

    public function test_webhook_adopts_the_attempt_when_the_gateway_id_was_never_persisted(): void
    {
        $tenant = $this->mpTenant();
        $order = Order::factory()->create(['tenant_id' => $tenant->id, 'payment_method' => 'mp_pix']);
        $attempt = Payment::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'gateway' => 'mercadopago',
            'amount_cents' => $order->total_cents, 'status' => 'created', 'idempotency_key' => 'k-1',
        ]);
        $this->fakeMpPaymentLookup('777000601', 'approved', $order);

        $this->postMpWebhook($tenant, '777000601', $this->mpSignedHeaders('777000601'))
            ->assertOk()->assertJson(['ok' => true, 'transitioned' => true]);

        $this->assertSame('777000601', $attempt->refresh()->gateway_payment_id);
        $this->assertSame('approved', $attempt->refresh()->status);
        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count(), 'criou um Payment duplicado em vez de adotar a tentativa');
    }

    public function test_payment_approved_after_the_order_was_cancelled_is_recorded_as_an_incident_and_does_not_reopen_the_order(): void
    {
        $tenant = $this->mpTenant();
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id, 'payment_method' => 'mp_pix', 'status' => OrderStatus::CANCELLED,
        ]);
        Payment::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'gateway' => 'mercadopago',
            'amount_cents' => $order->total_cents, 'status' => 'failed', 'idempotency_key' => 'k-2',
        ]);
        $this->fakeMpPaymentLookup('777000602', 'approved', $order);
        $logs = $this->captureLogs();

        $this->postMpWebhook($tenant, '777000602', $this->mpSignedHeaders('777000602'))->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->orderStatusOf($order), 'o webhook não pode reabrir o pedido (o estoque pode ter sido vendido)');
        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status, 'o dinheiro entrou: o financeiro precisa refletir');
        $incidents = $this->logsNamed($logs, 'payment.incident.paid_on_cancelled_order');
        $this->assertCount(1, $incidents);
        $this->assertSame('critical', $incidents[0]['level']);
        $this->assertSame($order->id, $incidents[0]['context']['order_id']);
    }
}

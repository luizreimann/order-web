<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * O webhook de ORDERS do Mercado Pago: tópico `order` (`action: order.processed`...), `data.id` =
 * id da order (`ORD...`), assinatura `x-signature`/`x-request-id` sobre `id:<data.id da query>`.
 * As garantias de segurança/idempotência do webhook em si estão em `WebhookIdempotencyTest`;
 * aqui só o que é específico de Orders.
 */
class OrdersWebhookTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const ORDER_ID = 'ORD01M28P44G5FG8RJPM579EH56FV';

    public function test_the_notification_from_the_mp_doc_is_authenticated_by_the_query_data_id_and_applied_from_the_api(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, self::ORDER_ID);
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'approved', $order);

        // Exatamente a notificação da documentação: `POST ?data.id=ORD...&type=order`, corpo com `action: order.processed`.
        $requestId = '2066ca19-c6f1-498a-be75-1923005edd06';
        $ts = '1742505638683';
        $v1 = hash_hmac('sha256', 'id:'.strtolower(self::ORDER_ID).";request-id:{$requestId};ts:{$ts};", 'whsec-test');

        $this->postJson('/webhooks/mercadopago/'.$tenant->id.'?data.id='.self::ORDER_ID.'&type=order', [
            'action' => 'order.processed', 'api_version' => 'v1', 'live_mode' => true, 'type' => 'order', 'user_id' => '11111111',
            'data' => ['id' => self::ORDER_ID, 'status' => 'processed', 'status_detail' => 'accredited', 'total_amount' => '200.00', 'type' => 'online', 'version' => 1],
        ], ['x-signature' => "ts={$ts},v1={$v1}", 'x-request-id' => $requestId])
            ->assertOk()->assertJson(['ok' => true, 'transitioned' => true]);

        $this->assertSame(PaymentStatus::PAID, $order->refresh()->payment_status);
        $this->assertSame('approved', $payment->refresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/v1/orders/'.self::ORDER_ID));
    }

    public function test_a_signature_over_a_different_query_id_is_rejected_even_if_the_body_id_matches_it(): void
    {
        $tenant = $this->mpTenant();
        [$order] = $this->orderWithMpPayment($tenant, self::ORDER_ID);
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'approved', $order);

        // Assinatura válida para OUTRO id (replay): query e corpo divergentes => 401, nada consultado.
        $this->postJson('/webhooks/mercadopago/'.$tenant->id.'?data.id=ORDOUTRO000000000001&type=order', [
            'type' => 'order', 'data' => ['id' => self::ORDER_ID],
        ], $this->mpSignedHeaders('ORDOUTRO000000000001'))->assertStatus(401);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_the_old_payment_topic_is_acknowledged_and_ignored(): void
    {
        $tenant = $this->mpTenant();
        [$order] = $this->orderWithMpPayment($tenant, self::ORDER_ID);
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'approved', $order);

        $this->postJson('/webhooks/mercadopago/'.$tenant->id, ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => self::ORDER_ID]], $this->mpSignedHeaders(self::ORDER_ID))
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_a_legacy_numeric_payments_id_is_acknowledged_without_error_or_retry(): void
    {
        $tenant = $this->mpTenant();
        // Pagamento da API antiga (id numérico) ainda gravado: nada de consulta nem de 503.
        [$order] = $this->orderWithMpPayment($tenant, '987654321');
        Http::fake();

        $this->postMpWebhook($tenant, '987654321', $this->mpSignedHeaders('987654321'))
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame(PaymentStatus::UNPAID, $order->refresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_the_reconciler_skips_a_legacy_numeric_payments_id_without_calling_the_api(): void
    {
        $tenant = $this->mpTenant();
        [$order] = $this->orderWithMpPayment($tenant, '987654321');
        Http::fake();

        $this->assertNull(app(PaymentReconciler::class)->reconcileOrder($tenant, $order, throttle: false));

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public function test_an_unknown_status_from_the_api_never_pays_nor_cancels_and_keeps_the_raw_value(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, self::ORDER_ID, 'pending');
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'status_do_futuro', $order);

        $this->postMpWebhook($tenant, self::ORDER_ID, $this->mpSignedHeaders(self::ORDER_ID))->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::UNPAID, $order->payment_status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame('in_process', $payment->refresh()->status, 'segue em consulta');
        $this->assertSame('status_do_futuro', $payment->meta['gateway_details']['order_status'], 'o bruto do MP fica guardado');
        $this->assertSame('unknown', $payment->meta['gateway_details']['note']);
    }

    public function test_an_expired_pix_cancels_the_order_through_the_webhook(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, self::ORDER_ID, 'pending');
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'expired', $order);

        // `action` de uma order expirada: canceled|failed, com `data.status_detail: expired`.
        $this->postMpWebhook($tenant, self::ORDER_ID, $this->mpSignedHeaders(self::ORDER_ID), ['action' => 'order.canceled'])->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame('cancelled', $payment->refresh()->status);
        $this->assertSame('expired', $payment->status_detail);
    }

    public function test_the_qr_is_stored_when_the_webhook_brings_it_and_a_later_empty_notification_does_not_erase_it(): void
    {
        $tenant = $this->mpTenant();
        [$order, $payment] = $this->orderWithMpPayment($tenant, self::ORDER_ID, 'in_process');
        $this->fakeMpPaymentLookup(self::ORDER_ID, 'pending', $order);

        $this->postMpWebhook($tenant, self::ORDER_ID, $this->mpSignedHeaders(self::ORDER_ID), ['action' => 'order.created'])->assertOk();

        $stored = Payment::withoutTenantScope()->findOrFail($payment->id);
        $this->assertSame(MercadoPagoOrders::QR_CODE, data_get($stored->meta, 'point_of_interaction.transaction_data.qr_code'));
        $this->assertSame('waiting_transfer', $stored->status_detail);
    }
}

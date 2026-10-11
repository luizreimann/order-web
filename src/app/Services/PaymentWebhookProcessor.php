<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\WebhookEvent;
use App\Contracts\WebhookResult;
use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Aplica uma notificação de gateway JÁ AUTENTICADA, de forma idempotente (E3.3).
 *
 * Independe do gateway: ele só fornece {@see PaymentGatewayInterface::parseWebhook()} e
 * {@see PaymentGatewayInterface::getPayment()}.
 *
 * Garantias:
 *  - o STATUS vem da consulta à API com as credenciais do tenant, nunca do corpo;
 *  - nada de HTTP dentro de transação: a consulta acontece ANTES do `DB::transaction`;
 *  - lock sempre na ordem pedido → pagamento (a mesma de {@see PaymentService} e de
 *    {@see OrderService::updateOrder()}), então não há deadlock entre eles;
 *  - cada transição `(gateway, pagamento, status)` é aplicada UMA vez: o estado é
 *    comparado sob lock e o UNIQUE de `payment_webhook_events` é a rede de segurança;
 *  - todo o processamento roda no contexto do tenant resolvido pela rota: um pagamento
 *    de outro tenant simplesmente não é encontrado.
 */
class PaymentWebhookProcessor
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  Tenant|null  $tenant  já autenticado pela rota; null = descobrir pelo pagamento registrado
     */
    public function process(PaymentGatewayInterface $gateway, array $payload, array $headers, ?Tenant $tenant = null): WebhookResult
    {
        $event = $gateway->parseWebhook($payload, $headers);

        if ($event->ignoredReason !== null) {
            return WebhookResult::ignored($event->ignoredReason);
        }

        if ($event->paymentId === null) {
            return WebhookResult::failed($event->invalidReason ?? 'Payload inválido.');
        }

        $tenant ??= $this->tenantOfRecordedPayment($gateway, $event->paymentId);

        if ($tenant === null) {
            return WebhookResult::ignored('Pedido não encontrado para payment_id: '.$event->paymentId);
        }

        return app(TenantContext::class)->runFor(
            $tenant,
            fn () => $this->processForTenant($gateway, $tenant, $event, $event->paymentId)
        );
    }

    /**
     * Tenant dono de um pagamento já registrado. Só a rota SEM tenant na URL (legado) e
     * chamadas diretas usam isto; a rota com tenant autentica antes e nem precisa.
     */
    public function tenantOfRecordedPayment(PaymentGatewayInterface $gateway, string $paymentId): ?Tenant
    {
        // CROSS-TENANT DELIBERADO: sem tenant na URL não há contexto, e o pagamento só
        // pode ser localizado pelo par (gateway, id no gateway) — globalmente único
        // (UNIQUE em `payments`). Lê UMA coluna do pagamento; não toca pedidos nem API.
        $tenantId = Payment::withoutTenantScope()
            ->where('gateway', $gateway->key())
            ->where('gateway_payment_id', $paymentId)
            ->value('tenant_id');

        return $tenantId ? Tenant::query()->find($tenantId) : null;
    }

    private function processForTenant(PaymentGatewayInterface $gateway, Tenant $tenant, WebhookEvent $event, string $paymentId): WebhookResult
    {
        // Reenvio da MESMA notificação: corta antes de gastar uma chamada à API.
        if ($event->requestId !== null) {
            $seen = PaymentWebhookEvent::query()
                ->where('gateway', $gateway->key())
                ->where('request_id', $event->requestId)
                ->where('gateway_payment_id', $paymentId)
                ->first();

            if ($seen !== null) {
                $order = $seen->order_id ? Order::query()->find($seen->order_id) : null;
                $current = $order instanceof Order ? $order->payment_status : PaymentStatus::UNPAID;

                return WebhookResult::unchanged((string) $seen->order_id, $current->value);
            }
        }

        // Fonte da verdade: a API, com o token do tenant. Fora de transação.
        $remote = $gateway->getPayment($tenant, $paymentId);

        if ($remote === null || $remote->status === null) {
            return WebhookResult::retry('Não foi possível consultar o pagamento no gateway.');
        }

        return DB::transaction(fn () => $this->applyTransition($gateway, $tenant, $event, $paymentId, $remote));
    }

    private function applyTransition(PaymentGatewayInterface $gateway, Tenant $tenant, WebhookEvent $event, string $paymentId, PaymentResult $remote): WebhookResult
    {
        $key = $gateway->key();
        $status = (string) $remote->status;

        // 1) Descobre o pedido SEM lock (só para saber qual travar).
        $known = Payment::query()->where('gateway', $key)->where('gateway_payment_id', $paymentId)->first();
        $orderId = $known instanceof Payment ? $known->order_id : (string) data_get($remote->meta, 'metadata.order_id');

        if (! Str::isUuid($orderId)) {
            return WebhookResult::ignored('Pedido não encontrado para payment_id: '.$paymentId);
        }

        // 2) Lock do PEDIDO e só então do pagamento (ordem única, ver docblock da classe).
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        if ($order === null) {
            return WebhookResult::ignored('Pedido não encontrado para payment_id: '.$paymentId);
        }

        $metaTenantId = (string) data_get($remote->meta, 'metadata.tenant_id');
        $metaOrderId = (string) data_get($remote->meta, 'metadata.order_id');

        if (($metaTenantId !== '' && $metaTenantId !== (string) $order->tenant_id)
            || ($metaOrderId !== '' && $metaOrderId !== (string) $order->id)) {
            return WebhookResult::ignored('Metadata não corresponde ao pedido.');
        }

        // 3) Relê o pagamento SOB lock: outro processo pode ter adotado/atualizado enquanto
        //    esperávamos o lock do pedido.
        $payment = Payment::query()
            ->where('gateway', $key)
            ->where('gateway_payment_id', $paymentId)
            ->lockForUpdate()
            ->first();

        $payment ??= $this->adoptAttempt($gateway, $order, $paymentId);

        if ($payment->gateway_payment_id === $paymentId && $payment->status === $status) {
            return WebhookResult::unchanged($order->id, $order->payment_status->value, $remote->meta);
        }

        $previousStatus = $payment->status;

        $inserted = DB::table('payment_webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'gateway' => $key,
            'gateway_payment_id' => $paymentId,
            'status' => $status,
            'previous_status' => $previousStatus,
            'request_id' => $event->requestId,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            // Esta transição já foi registrada (entrega concorrente que perdeu a corrida).
            return WebhookResult::unchanged($order->id, $order->payment_status->value, $remote->meta);
        }

        $now = now()->toIso8601String();

        $payment->gateway_payment_id = $paymentId;
        $payment->status = $status;
        $payment->status_detail = $remote->statusDetail;
        $payment->meta = array_merge($payment->meta ?? [], [
            'payment_method_id' => data_get($remote->meta, 'payment_method_id'),
            'point_of_interaction' => data_get($remote->meta, 'point_of_interaction'),
            'last_webhook_at' => $now,
        ]);
        $payment->save();

        $gatewayStatus = $remote->gatewayStatus();
        $mapped = $gatewayStatus?->toPaymentStatus();

        $order->meta = array_merge($order->meta ?? [], [
            $key => array_merge((array) data_get($order->meta, $key, []), [
                'payment_id' => $paymentId,
                'status' => $status,
                'status_detail' => $remote->statusDetail,
                'payment_method_id' => data_get($remote->meta, 'payment_method_id'),
                'point_of_interaction' => data_get($remote->meta, 'point_of_interaction'),
                'last_webhook_at' => $now,
            ]),
        ]);

        if ($mapped !== null) {
            $order->payment_status = $mapped;
        }

        $order->save();

        $this->afterTransition($order, $payment, $gatewayStatus, $mapped, $paymentId, $key);

        Log::info('payment.webhook.transition', [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'gateway' => $key,
            'gateway_payment_id' => $paymentId,
            'from' => $previousStatus,
            'to' => $status,
        ]);

        return WebhookResult::processed($order->id, $order->payment_status->value, $remote->meta);
    }

    /**
     * Efeitos no pedido além do `payment_status`.
     */
    private function afterTransition(Order $order, Payment $payment, ?GatewayPaymentStatus $gatewayStatus, ?PaymentStatus $mapped, string $paymentId, string $gatewayKey): void
    {
        // Recusa/cancelamento assíncrono (ex.: PIX expirado) de um pedido ainda em aberto:
        // a mesma compensação da falha síncrona, com a devolução idempotente do estoque.
        if ($gatewayStatus?->isDeclined()
            && $order->status === OrderStatus::PENDING
            && $order->payment_status === PaymentStatus::UNPAID) {
            app(OrderService::class)->updateOrder($order, OrderStatus::CANCELLED, PaymentStatus::UNPAID, $order->payment_method);

            return;
        }

        // O dinheiro entrou para um pedido que já foi cancelado (ex.: timeout ambíguo na
        // cobrança). NÃO reabrimos o pedido (o estoque pode ter sido vendido) nem
        // rebaixamos estoque: só registramos para estorno/atendimento manual.
        if ($mapped === PaymentStatus::PAID && $order->status === OrderStatus::CANCELLED) {
            Log::critical('payment.incident.paid_on_cancelled_order', [
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => $gatewayKey,
                'gateway_payment_id' => $paymentId,
                'amount_cents' => $payment->amount_cents,
            ]);
        }
    }

    /**
     * Pagamento aprovado/avisado cujo id nunca foi gravado (falha após a cobrança): adota a
     * tentativa mais recente do pedido sem id, em vez de criar um pagamento duplicado.
     */
    private function adoptAttempt(PaymentGatewayInterface $gateway, Order $order, string $paymentId): Payment
    {
        $attempt = Payment::query()
            ->where('order_id', $order->id)
            ->where('gateway', $gateway->key())
            ->whereNull('gateway_payment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($attempt !== null) {
            return $attempt;
        }

        return Payment::query()->create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'gateway' => $gateway->key(),
            'gateway_payment_id' => null,
            'amount_cents' => $order->total_cents,
            'status' => GatewayPaymentStatus::CREATED->value,
            'meta' => ['origin' => 'webhook'],
        ]);
    }
}

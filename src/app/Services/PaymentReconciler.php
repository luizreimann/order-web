<?php

namespace App\Services;

use App\Contracts\CancelsPayments;
use App\Contracts\ValidatesPaymentIds;
use App\Contracts\WebhookResult;
use App\Enums\GatewayPaymentStatus;
use App\Gateways\PaymentGatewayManager;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciliação SOB DEMANDA: quem decide `paid` quando não há webhook (APP_URL local, túnel
 * fora do ar, notificação perdida) é a consulta ativa à API do gateway.
 *
 * Não tem lógica de transição própria: delega a {@see PaymentWebhookProcessor::reconcile()},
 * o MESMO caminho idempotente do webhook (lock no pedido e no pagamento, UNIQUE de
 * eventos, estoque devolvido em recusa, `paid_on_cancelled_order`). Um webhook que chegue
 * depois, ou duas consultas simultâneas, resultam em UMA transição.
 *
 * Pix com a janela de pagamento vencida ({@see PixExpiryEnforcer}) segue o mesmo ponto de entrada
 * (polling do checkout e `payments:reconcile`): a consulta, quando vencido, também cancela no gateway.
 *
 * Aqui só ficam duas coisas:
 *  - o THROTTLE: no máximo uma consulta ao gateway a cada {@see self::THROTTLE_SECONDS}
 *    por pagamento (`Cache::add` atômico), para o polling de várias abas/clientes não
 *    virar uma rajada contra a API do gateway;
 *  - a blindagem: falha do gateway, da rede ou do cache NUNCA propaga; devolve null e loga
 *    (sem token nem corpo de requisição).
 */
class PaymentReconciler
{
    public const THROTTLE_SECONDS = 3;

    /** Estados ainda sem decisão: o gateway pode mudá-los. `created` + id = resposta não consolidada. */
    private const OPEN_STATUSES = [
        GatewayPaymentStatus::CREATED,
        GatewayPaymentStatus::PENDING,
        GatewayPaymentStatus::IN_PROCESS,
        GatewayPaymentStatus::AUTHORIZED,
    ];

    public function __construct(
        private readonly PaymentWebhookProcessor $processor,
        private readonly PaymentGatewayManager $gateways,
        private readonly PixExpiryEnforcer $expiry,
    ) {}

    /** @return list<string> valores brutos de `payments.status` que ainda merecem consulta */
    public static function openStatuses(): array
    {
        return array_map(fn (GatewayPaymentStatus $s) => $s->value, self::OPEN_STATUSES);
    }

    public static function isOpen(Payment $payment): bool
    {
        return $payment->gateway_payment_id !== null
            && $payment->gateway_payment_id !== ''
            && in_array($payment->status, self::openStatuses(), true);
    }

    /**
     * Reconcilia a tentativa mais recente do pedido que já tem id no gateway.
     *
     * @return WebhookResult|null null = não consultou (throttle, nada em aberto, erro)
     */
    public function reconcileOrder(Tenant $tenant, Order $order, bool $throttle = true): ?WebhookResult
    {
        $payment = Payment::query()
            ->forTenant($tenant->id)
            ->where('order_id', $order->id)
            ->whereNotNull('gateway_payment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $payment instanceof Payment ? $this->reconcile($tenant, $payment, $throttle) : null;
    }

    /**
     * @param  bool  $throttle  false só para o comando de varredura (já espaçado por natureza)
     * @return WebhookResult|null null = não consultou (throttle, estado final, erro)
     */
    public function reconcile(Tenant $tenant, Payment $payment, bool $throttle = true): ?WebhookResult
    {
        if (! self::isOpen($payment) || ! $this->gateways->has((string) $payment->gateway)) {
            return null;
        }

        try {
            $gateway = $this->gateways->forProvider((string) $payment->gateway);

            // Id que o gateway não sabe consultar (ex.: id numérico da API antiga de Payments do
            // Mercado Pago, anterior à migração para Orders): não há o que reconciliar.
            if ($gateway instanceof ValidatesPaymentIds && ! $gateway->isLookupableId((string) $payment->gateway_payment_id)) {
                return null;
            }

            // Atômico no store compartilhado (redis/database/memcached): sob concorrência só
            // UMA requisição ganha a vaga e chama o gateway; as outras devolvem o estado atual.
            if ($throttle && ! Cache::add($this->throttleKey($payment), 1, self::THROTTLE_SECONDS)) {
                return null;
            }

            // Pix cuja janela de pagamento venceu: a MESMA consulta decide (pago? cancelado? ainda
            // aguardando?) e, se ainda aguardando, cancela no gateway antes de cancelar o pedido
            // (ver PixExpiryEnforcer). Os demais pagamentos seguem a reconciliação comum.
            $result = $gateway instanceof CancelsPayments && $this->expiry->isDue($payment)
                ? $this->expiry->enforce($gateway, $tenant, $payment)
                : $this->processor->reconcile($gateway, $tenant, (string) $payment->gateway_payment_id);
        } catch (Throwable $e) {
            // Só a classe: a mensagem de exceções HTTP pode trazer URL/trechos da resposta.
            Log::warning('payment.reconcile.failed', $this->context($tenant, $payment) + ['exception' => $e::class]);

            return null;
        }

        if ($result->retryable) {
            Log::warning('payment.reconcile.gateway_unavailable', $this->context($tenant, $payment));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function context(Tenant $tenant, Payment $payment): array
    {
        return [
            'tenant_id' => $tenant->id,
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'gateway' => $payment->gateway,
        ];
    }

    private function throttleKey(Payment $payment): string
    {
        return 'payment-reconcile:'.$payment->id;
    }
}

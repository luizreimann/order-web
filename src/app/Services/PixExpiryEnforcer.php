<?php

namespace App\Services;

use App\Contracts\CancelsPayments;
use App\Contracts\PaymentCancellation;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\WebhookResult;
use App\Enums\GatewayPaymentStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\PixWindow;
use Illuminate\Support\Facades\Log;

/**
 * Impõe a janela de pagamento do Pix ({@see PixWindow}) NO SERVIDOR, sem depender do navegador.
 *
 * Quando a janela (+ tolerância) de um Pix em aberto venceu:
 *
 *  1. consulta a order no gateway e aplica o estado pelo caminho idempotente do webhook
 *     ({@see PaymentWebhookProcessor::reconcileWithRemote()}). Se já está PAGA, é o fim: um pedido
 *     pago NUNCA é cancelado aqui; se o gateway já mostra um estado final (cancelada, expirada,
 *     recusada), o mesmo caminho cancela o pedido e devolve o estoque, uma vez;
 *  2. se ainda aguardando o pagamento, CANCELA a order no gateway ({@see CancelsPayments});
 *  3. só depois, reconsulta e aplica o que o gateway disser: o pedido local só é cancelado (e o
 *     estoque devolvido) quando o GATEWAY mostra o cancelamento. Cancelamento que falha por
 *     rede/5xx não toca no pedido nem no estoque: fica pendente e o próximo ciclo (polling do
 *     checkout ou `payments:reconcile`) tenta de novo; um conflito (pago no último instante) cai
 *     na mesma reconsulta e o estado do gateway é respeitado.
 *
 * Nenhuma chamada ao gateway acontece dentro de transação; os locks pedido → pagamento são os do
 * processador de webhook, então duas execuções simultâneas resultam em UMA transição.
 */
class PixExpiryEnforcer
{
    public function __construct(private readonly PaymentWebhookProcessor $processor) {}

    /** Pix em aberto, com prazo gravado, cuja janela (+ tolerância) já passou? */
    public function isDue(Payment $payment): bool
    {
        return PaymentReconciler::isOpen($payment) && PixWindow::isDue($payment->meta);
    }

    public function enforce(PaymentGatewayInterface $gateway, Tenant $tenant, Payment $payment): WebhookResult
    {
        $id = (string) $payment->gateway_payment_id;

        $remote = $gateway->getPayment($tenant, $id);

        if ($remote === null || $remote->status === null) {
            Log::warning('payment.pix_expiry.lookup_failed', $this->context($tenant, $payment));

            return WebhookResult::retry('Não foi possível consultar o pagamento no gateway.');
        }

        // Pago (ou qualquer estado final): aplica e termina. Nunca cancela o que já está pago.
        $result = $this->processor->reconcileWithRemote($gateway, $tenant, $id, $remote);

        if ($remote->gatewayStatus() !== GatewayPaymentStatus::PENDING) {
            if ($remote->isPending()) {
                // Ex.: `processing/in_process` (a order de Pix sem QR): o MP só cancela `created`
                // e `action_required`; fica pendente e é reconsultado no próximo ciclo.
                Log::warning('payment.pix_expiry.not_cancelable_state', $this->context($tenant, $payment) + ['gateway_status' => $remote->status]);
            }

            return $result;
        }

        if (! $gateway instanceof CancelsPayments) {
            return $result;
        }

        $cancellation = $gateway->cancelPayment($tenant, $id, 'pix-expiry:'.$payment->id);

        if ($cancellation->outcome === PaymentCancellation::UNAVAILABLE) {
            // Rede/5xx/erro desconhecido: NADA muda localmente (nem pedido, nem estoque).
            Log::warning('payment.pix_expiry.cancel_failed', $this->context($tenant, $payment) + array_filter([
                'http_status' => $cancellation->httpStatus,
                'error_code' => $cancellation->errorCode,
            ], fn ($v) => $v !== null));

            return WebhookResult::retry('Não foi possível cancelar o Pix no gateway.');
        }

        // Cancelado, já cancelado ou NÃO cancelável (o estado mudou, ex.: pago agora): em todos os
        // casos a verdade é o gateway; reconsulta e aplica.
        $after = $gateway->getPayment($tenant, $id);

        if ($after === null || $after->status === null) {
            Log::warning('payment.pix_expiry.lookup_failed', $this->context($tenant, $payment) + ['after' => $cancellation->outcome]);

            return WebhookResult::retry('Não foi possível reconsultar o pagamento no gateway.');
        }

        $final = $this->processor->reconcileWithRemote($gateway, $tenant, $id, $after);

        if ($after->isPending()) {
            Log::warning('payment.pix_expiry.still_open', $this->context($tenant, $payment) + [
                'cancel' => $cancellation->outcome,
                'gateway_status' => $after->status,
            ]);
        } else {
            Log::info('payment.pix_expiry.settled', $this->context($tenant, $payment) + [
                'cancel' => $cancellation->outcome,
                'gateway_status' => $after->status,
            ]);
        }

        return $final;
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
}

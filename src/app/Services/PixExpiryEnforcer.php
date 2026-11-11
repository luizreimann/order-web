<?php

namespace App\Services;

use App\Contracts\CancelsPayments;
use App\Contracts\PaymentCancellation;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\ReportsMissingPayments;
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

    /** Pix em aberto cuja janela (+ tolerância) já passou? (prazo gravado, ou created_at + janela) */
    public function isDue(Payment $payment): bool
    {
        return PaymentReconciler::isOpen($payment) && PixWindow::isDueFor($payment);
    }

    public function enforce(PaymentGatewayInterface $gateway, Tenant $tenant, Payment $payment): WebhookResult
    {
        $id = (string) $payment->gateway_payment_id;

        $remote = $gateway->getPayment($tenant, $id);

        if ($remote === null || $remote->status === null) {
            // "Não existe mais" (404 `order_not_found`) é definitivo; qualquer outra falha não é.
            if ($gateway instanceof ReportsMissingPayments && $gateway->paymentIsMissing($tenant, $id)) {
                Log::warning('payment.pix_expiry.order_not_found', $this->context($tenant, $payment));

                return $this->processor->reconcileWithRemote(
                    $gateway,
                    $tenant,
                    $id,
                    PaymentResult::success($id, GatewayPaymentStatus::CANCELLED->value, 'pix_order_not_found'),
                );
            }

            Log::warning('payment.pix_expiry.lookup_failed', $this->context($tenant, $payment));

            return WebhookResult::retry('Não foi possível consultar o pagamento no gateway.');
        }

        // Pago (ou qualquer estado final): aplica e termina. Nunca cancela o que já está pago.
        $result = $this->processor->reconcileWithRemote($gateway, $tenant, $id, $remote);

        if ($remote->gatewayStatus() !== GatewayPaymentStatus::PENDING) {
            if ($remote->isPending()) {
                // Ex.: `processing/in_process`: o MP só cancela `created` e `action_required`.
                if (! $this->qrWasAvailable($payment)) {
                    return $this->cancelPixNeverPayable($gateway, $tenant, $payment, $id, $remote);
                }

                // Já houve um QR (alguém pode ter pago): só o estado do gateway decide; o próximo
                // ciclo reconsulta.
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

    /** O QR (ou o copia e cola) deste Pix chegou a existir? Se sim, alguém pode ter pago. */
    private function qrWasAvailable(Payment $payment): bool
    {
        $td = Payment::query()->whereKey($payment->id)->value('meta');
        $td = is_string($td) ? json_decode($td, true) : $td;
        $data = data_get($td, 'point_of_interaction.transaction_data');

        return is_array($data) && (filled($data['qr_code'] ?? null) || filled($data['qr_code_base64'] ?? null));
    }

    /**
     * O gateway não gerou QR até o fim da janela e a order não é cancelável lá (`processing`): o
     * cliente nunca viu um código pagável, então o pedido é cancelado e o estoque volta. Se o
     * gateway ainda vier a aprovar, o webhook/reconciliação registra o incidente
     * `paid_on_cancelled_order` (pagamento sobre pedido cancelado), como em qualquer outro caso.
     */
    private function cancelPixNeverPayable(PaymentGatewayInterface $gateway, Tenant $tenant, Payment $payment, string $id, PaymentResult $remote): WebhookResult
    {
        Log::warning('payment.pix_expiry.cancelled_without_qr', $this->context($tenant, $payment) + ['gateway_status' => $remote->status]);

        return $this->processor->reconcileWithRemote(
            $gateway,
            $tenant,
            $id,
            PaymentResult::success($id, GatewayPaymentStatus::CANCELLED->value, 'pix_expired_without_qr', $remote->meta),
        );
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

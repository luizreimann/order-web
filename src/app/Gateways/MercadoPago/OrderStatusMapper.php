<?php

namespace App\Gateways\MercadoPago;

use App\Enums\GatewayPaymentStatus;

/**
 * TABELA explícita: `status`/`status_detail` da ORDER e da TRANSAÇÃO (`transactions.payments[0]`)
 * do Mercado Pago (API de Orders) → {@see GatewayPaymentStatus} (o vocabulário do sistema, que
 * `payments.status` guarda e `GatewayPaymentStatus::toPaymentStatus()` traduz para o pedido).
 *
 * Fontes: "Status da order" e "Status da transação" da documentação do Checkout Transparente
 * (Orders) e a tabela de equivalência do guia "Como migrar da Payments API para a Orders API".
 * O que a SANDBOX acrescentou: `canceled`/`canceled_transaction` (transação de uma order
 * cancelada, ausente da documentação) e `created` sem `status_detail` na order (modo manual).
 *
 * Nível (status → resultado; `*` = qualquer `status_detail`):
 *
 *   processed / accredited | partially_refunded → APPROVED  (reembolso parcial não rebaixa o pedido)
 *   created / *                                 → PENDING
 *   processing / * (in_process, pending_review_manual, in_review) → IN_PROCESS
 *   in_review / in_review                       → IN_PROCESS  (order em revisão)
 *   action_required / waiting_capture           → AUTHORIZED
 *   action_required / * (waiting_payment, waiting_transfer, waiting_retry, pending_challenge) → PENDING
 *   failed / *                                  → REJECTED
 *   canceled / *, expired / *                   → CANCELLED   (`failed/expired` segue `failed`: REJECTED;
 *                                                              os dois contam como "recusado" para o sistema)
 *   refunded / refunded                         → REFUNDED
 *   charged_back / in_process                   → IN_MEDIATION (contestação em curso: pedido segue pago)
 *   charged_back / settled | reimbursed | *     → CHARGED_BACK
 *
 * Regras de combinação (a order é o resumo; a transação, o detalhe):
 *  - APROVADO só se order E transação dizem aprovado; se a transação diz aprovado e a order
 *    ainda está em andamento (instante transitório), vale "em processamento";
 *  - transação aprovada e order recusada/cancelada é CONTRADIÇÃO: não paga, não cancela
 *    (fica "em processamento") e registra-se `note = conflict`;
 *  - qualquer valor FORA da tabela nunca vira pago e nunca derruba o registro: vira
 *    IN_PROCESS (continua sendo consultado) com `note = unknown` e o bruto fica em `meta`.
 *
 * Não tratado (decisão): `waiting_retry`/3DS (`pending_challenge`) são tratados como pendentes
 * genéricos; o checkout não usa 3DS nem captura manual, então os estados não ocorrem hoje.
 */
final class OrderStatusMapper
{
    public const NOTE_UNKNOWN = 'unknown';

    public const NOTE_CONFLICT = 'conflict';

    /**
     * @param  array<string, mixed>  $order  corpo da order (`GET /v1/orders/{id}` ou `data` de um 402)
     * @return array{status: GatewayPaymentStatus, detail: ?string, note: ?string, raw: array<string, ?string>}
     */
    public static function map(array $order): array
    {
        $payment = (array) data_get($order, 'transactions.payments.0', []);

        $orderStatus = self::str($order['status'] ?? null);
        $orderDetail = self::str($order['status_detail'] ?? null);
        $payStatus = self::str($payment['status'] ?? null);
        $payDetail = self::str($payment['status_detail'] ?? null);

        $raw = [
            'order_status' => $orderStatus,
            'order_status_detail' => $orderDetail,
            'payment_status' => $payStatus,
            'payment_status_detail' => $payDetail,
        ];

        $detail = $payDetail ?? $orderDetail;
        $orderLevel = $orderStatus === null ? null : self::level($orderStatus, $orderDetail);
        $payLevel = $payStatus === null ? null : self::level($payStatus, $payDetail);

        $unknown = fn (): array => [
            'status' => GatewayPaymentStatus::IN_PROCESS, 'detail' => $detail, 'note' => self::NOTE_UNKNOWN, 'raw' => $raw,
        ];
        $ok = fn (GatewayPaymentStatus $s, ?string $note = null): array => [
            'status' => $s, 'detail' => $detail, 'note' => $note, 'raw' => $raw,
        ];

        // Um dos níveis veio e não está na tabela: nunca decide (nem paga).
        if (($orderStatus !== null && $orderLevel === null) || ($payStatus !== null && $payLevel === null)) {
            return $unknown();
        }

        if ($orderLevel === null && $payLevel === null) {
            return $unknown();
        }

        // Só a order (ex.: modo manual, sem status de transação) ou só a transação.
        if ($payLevel === null) {
            return $ok($orderLevel);
        }

        if ($orderLevel === null) {
            // Aprovação exige os dois níveis; sem o da order, espera.
            return $payLevel === GatewayPaymentStatus::APPROVED ? $ok(GatewayPaymentStatus::IN_PROCESS) : $ok($payLevel);
        }

        if ($payLevel === GatewayPaymentStatus::APPROVED) {
            return match (true) {
                $orderLevel === GatewayPaymentStatus::APPROVED => $ok(GatewayPaymentStatus::APPROVED),
                in_array($orderLevel, [GatewayPaymentStatus::REFUNDED, GatewayPaymentStatus::CHARGED_BACK, GatewayPaymentStatus::IN_MEDIATION], true) => $ok($orderLevel),
                $orderLevel->isDeclined() => $ok(GatewayPaymentStatus::IN_PROCESS, self::NOTE_CONFLICT),
                default => $ok(GatewayPaymentStatus::IN_PROCESS),
            };
        }

        return $ok($payLevel);
    }

    private static function level(string $status, ?string $detail): ?GatewayPaymentStatus
    {
        return match ($status) {
            'processed' => match ($detail) {
                'accredited', 'partially_refunded' => GatewayPaymentStatus::APPROVED,
                default => null,
            },
            'created' => GatewayPaymentStatus::PENDING,
            'processing', 'in_review' => GatewayPaymentStatus::IN_PROCESS,
            'action_required' => $detail === 'waiting_capture' ? GatewayPaymentStatus::AUTHORIZED : GatewayPaymentStatus::PENDING,
            'failed' => GatewayPaymentStatus::REJECTED,
            'canceled', 'expired' => GatewayPaymentStatus::CANCELLED,
            'refunded' => GatewayPaymentStatus::REFUNDED,
            'charged_back' => $detail === 'in_process' ? GatewayPaymentStatus::IN_MEDIATION : GatewayPaymentStatus::CHARGED_BACK,
            default => null,
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

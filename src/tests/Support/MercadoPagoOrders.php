<?php

namespace Tests\Support;

use App\Models\Order;

/**
 * Corpos da API de ORDERS do Mercado Pago (`/v1/orders`) nos formatos OBSERVADOS na sandbox
 * real (ver `.sprint/06-refactor/05-development.md`, §15), para os `Http::fake()` dos testes.
 *
 * Os testes falam o vocabulário do SISTEMA (`approved`, `pending`, `in_process`, `rejected`,
 * `cancelled`...); aqui ele vira o par `status`/`status_detail` que a API devolve, nos dois
 * níveis (order e transação). Um valor fora da tabela passa cru, para exercitar "desconhecido".
 */
final class MercadoPagoOrders
{
    public const QR_CODE = '00020126580014br.gov.bcb.pix0136b76aa9c2-2ec4-4110-954e-ebfe34f05b61520400005303986540510.005802BR5912TESTPVBWOSBE6009Sao Paulo62240520mpqrinter715936942186304C3C0';

    public const QR_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAABWQAAAVkAQAAAAB79iQ=';

    public const TICKET_URL = 'https://www.mercadopago.com.br/sandbox/payments/00000000000/ticket?caller_id=1&hash=34cb0d7c';

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} order status, order detail, payment status, payment detail
     */
    public static function statuses(string $canonical): array
    {
        return match ($canonical) {
            'approved' => ['processed', 'accredited', 'processed', 'accredited'],
            'pending' => ['action_required', 'waiting_transfer', 'action_required', 'waiting_transfer'],
            'in_process' => ['processing', 'in_process', 'processing', 'in_process'],
            'authorized' => ['action_required', 'waiting_capture', 'action_required', 'waiting_capture'],
            'rejected' => ['failed', 'failed', 'failed', 'rejected_by_issuer'],
            'cancelled' => ['canceled', 'canceled', 'canceled', 'canceled_transaction'],
            'expired' => ['canceled', 'expired', 'expired', 'expired'],
            'refunded' => ['refunded', 'refunded', 'refunded', 'refunded'],
            'charged_back' => ['charged_back', 'settled', 'charged_back', 'settled'],
            default => [$canonical, $canonical, $canonical, $canonical],
        };
    }

    /**
     * @param  array<string, mixed>  $overrides  mesclados (recursivamente) sobre o corpo
     * @return array<string, mixed>
     */
    public static function body(string $orderId, string $canonical, ?Order $order = null, array $overrides = [], bool $pixWithQr = true): array
    {
        [$orderStatus, $orderDetail, $payStatus, $payDetail] = self::statuses($canonical);

        $paymentMethod = ['id' => 'pix', 'type' => 'bank_transfer'];
        if ($canonical === 'pending' && $pixWithQr) {
            $paymentMethod += ['ticket_url' => self::TICKET_URL, 'qr_code' => self::QR_CODE, 'qr_code_base64' => self::QR_BASE64];
        }

        $total = $order !== null ? number_format($order->total_cents / 100, 2, '.', '') : '1.00';

        return array_replace_recursive([
            'id' => $orderId,
            'type' => 'online',
            'processing_mode' => 'automatic',
            'external_reference' => $order?->id,
            'total_amount' => $total,
            'total_paid_amount' => $canonical === 'approved' ? $total : '0.00',
            'country_code' => 'BRA',
            'status' => $orderStatus,
            'status_detail' => $orderDetail,
            'capture_mode' => 'automatic',
            'currency' => 'BRL',
            'transactions' => ['payments' => [[
                'id' => 'PAY'.substr($orderId, 3),
                'amount' => $total,
                'status' => $payStatus,
                'status_detail' => $payDetail,
                'payment_method' => $paymentMethod,
            ]]],
        ], $overrides);
    }

    /** Corpo de um cartão recusado: HTTP 402 com `errors[]` e a order em `data`. */
    public static function declinedBody(string $orderId, ?Order $order = null, string $detail = 'rejected_by_issuer'): array
    {
        $data = self::body($orderId, 'rejected', $order, [
            'transactions' => ['payments' => [['status_detail' => $detail, 'payment_method' => ['id' => 'master', 'type' => 'credit_card']]]],
        ]);

        return [
            'errors' => [['code' => 'failed', 'message' => 'The following transactions failed', 'details' => [$data['transactions']['payments'][0]['id'].': '.$detail]]],
            'data' => $data,
        ];
    }

    /** Corpo de erro de validação/credencial da API de Orders. */
    public static function errorBody(string $code, string $message = 'erro', array $details = []): array
    {
        return ['errors' => [array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null])]];
    }
}

<?php

namespace Tests\Unit\Gateways;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentStatus;
use App\Gateways\MercadoPago\OrderStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A TABELA de status do Mercado Pago (Orders) → vocabulário do sistema. Cada linha é um par real
 * da documentação ("Status da order" / "Status da transação") ou observado na sandbox (marcado).
 */
class OrderStatusMapperTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string, ?string, ?string, GatewayPaymentStatus, ?PaymentStatus}>
     *                                                                                                       order status, order detail, payment status, payment detail, esperado, efeito no pedido
     */
    public static function table(): array
    {
        return [
            // --- aprovado / creditado => pago
            'processed/accredited' => ['processed', 'accredited', 'processed', 'accredited', GatewayPaymentStatus::APPROVED, PaymentStatus::PAID],
            'processed/partially_refunded (continua pago)' => ['processed', 'partially_refunded', 'processed', 'partially_refunded', GatewayPaymentStatus::APPROVED, PaymentStatus::PAID],
            // --- pendente
            'created/created (order manual, sem transação)' => ['created', null, null, null, GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'created/created com transação created' => ['created', 'created', 'created', 'created', GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'action_required/waiting_transfer (Pix)' => ['action_required', 'waiting_transfer', 'action_required', 'waiting_transfer', GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'action_required/waiting_payment' => ['action_required', 'waiting_payment', 'action_required', 'waiting_payment', GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'action_required/pending_challenge (3DS)' => ['action_required', 'pending_challenge', 'action_required', 'pending_challenge', GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'action_required/waiting_retry' => ['action_required', 'waiting_retry', 'action_required', 'waiting_retry', GatewayPaymentStatus::PENDING, PaymentStatus::UNPAID],
            'action_required/waiting_capture => autorizado' => ['action_required', 'waiting_capture', 'action_required', 'waiting_capture', GatewayPaymentStatus::AUTHORIZED, null],
            // --- em processamento (observado na sandbox: Pix e cartão CONT)
            'processing/in_process (sandbox)' => ['processing', 'in_process', 'processing', 'in_process', GatewayPaymentStatus::IN_PROCESS, PaymentStatus::UNPAID],
            'processing/pending_review_manual' => ['processing', 'in_process', 'processing', 'pending_review_manual', GatewayPaymentStatus::IN_PROCESS, PaymentStatus::UNPAID],
            'processing/in_review' => ['processing', 'in_process', 'processing', 'in_review', GatewayPaymentStatus::IN_PROCESS, PaymentStatus::UNPAID],
            'in_review/in_review (order)' => ['in_review', 'in_review', null, null, GatewayPaymentStatus::IN_PROCESS, PaymentStatus::UNPAID],
            // --- recusado / cancelado / expirado => falha (estoque devolvido pelo caminho existente)
            'failed/failed + rejected_by_issuer (sandbox: cartão OTHE)' => ['failed', 'failed', 'failed', 'rejected_by_issuer', GatewayPaymentStatus::REJECTED, null],
            'failed/failed + high_risk' => ['failed', 'failed', 'failed', 'high_risk', GatewayPaymentStatus::REJECTED, null],
            'failed/failed + insufficient_amount' => ['failed', 'failed', 'failed', 'insufficient_amount', GatewayPaymentStatus::REJECTED, null],
            'failed/expired' => ['failed', 'expired', 'failed', 'expired', GatewayPaymentStatus::REJECTED, null],
            'canceled/canceled + canceled_transaction (sandbox)' => ['canceled', 'canceled', 'canceled', 'canceled_transaction', GatewayPaymentStatus::CANCELLED, null],
            'canceled/expired (Pix vencido)' => ['canceled', 'expired', 'expired', 'expired', GatewayPaymentStatus::CANCELLED, null],
            'canceled/expired com transação canceled' => ['canceled', 'expired', 'canceled', 'canceled', GatewayPaymentStatus::CANCELLED, null],
            // --- reembolso / contestação
            'refunded/refunded' => ['refunded', 'refunded', 'refunded', 'refunded', GatewayPaymentStatus::REFUNDED, PaymentStatus::REFUNDED],
            'charged_back/in_process (contestação em curso)' => ['charged_back', 'in_process', 'charged_back', 'in_process', GatewayPaymentStatus::IN_MEDIATION, null],
            'charged_back/settled' => ['charged_back', 'settled', 'charged_back', 'settled', GatewayPaymentStatus::CHARGED_BACK, PaymentStatus::REFUNDED],
            'charged_back/reimbursed' => ['charged_back', 'reimbursed', 'charged_back', 'reimbursed', GatewayPaymentStatus::CHARGED_BACK, PaymentStatus::REFUNDED],
        ];
    }

    #[DataProvider('table')]
    public function test_the_status_table(string $orderStatus, ?string $orderDetail, ?string $payStatus, ?string $payDetail, GatewayPaymentStatus $expected, ?PaymentStatus $effect): void
    {
        $mapped = OrderStatusMapper::map(self::order($orderStatus, $orderDetail, $payStatus, $payDetail));

        $this->assertSame($expected, $mapped['status']);
        $this->assertNull($mapped['note'], 'um par da tabela nunca é "desconhecido"');
        $this->assertSame($effect, $mapped['status']->toPaymentStatus());
    }

    public function test_the_detail_comes_from_the_transaction_then_the_order(): void
    {
        $this->assertSame('rejected_by_issuer', OrderStatusMapper::map(self::order('failed', 'failed', 'failed', 'rejected_by_issuer'))['detail']);
        $this->assertSame('created', OrderStatusMapper::map(self::order('created', 'created', null, null))['detail']);
        $this->assertNull(OrderStatusMapper::map(self::order('created', null, null, null))['detail']);
    }

    public function test_the_raw_values_are_kept(): void
    {
        $this->assertSame([
            'order_status' => 'processed', 'order_status_detail' => 'accredited',
            'payment_status' => 'processed', 'payment_status_detail' => 'accredited',
        ], OrderStatusMapper::map(self::order('processed', 'accredited', 'processed', 'accredited'))['raw']);
    }

    /** @return array<string, array{?string, ?string, ?string, ?string}> */
    public static function neverPaid(): array
    {
        return [
            'order aprovada mas transação em processamento' => ['processed', 'accredited', 'processing', 'in_process'],
            'transação aprovada mas order em processamento (instante transitório)' => ['processing', 'in_process', 'processed', 'accredited'],
            'transação aprovada mas order pendente' => ['action_required', 'waiting_transfer', 'processed', 'accredited'],
            'processed com detalhe fora da tabela' => ['processed', 'accredited_late', 'processed', 'accredited_late'],
            'processed sem detalhe' => ['processed', null, 'processed', null],
            'order aprovada, transação com status novo' => ['processed', 'accredited', 'novo_status', 'x'],
            'status novo na order' => ['status_novo', 'x', null, null],
            'tudo novo' => ['a', 'b', 'c', 'd'],
            'transação aprovada mas order recusada (contradição)' => ['failed', 'failed', 'processed', 'accredited'],
            'transação aprovada mas order cancelada (contradição)' => ['canceled', 'canceled', 'processed', 'accredited'],
            'só a transação aprovada, order desconhecida' => ['status_novo', 'x', 'processed', 'accredited'],
            'vazio' => [null, null, null, null],
        ];
    }

    #[DataProvider('neverPaid')]
    public function test_anything_ambiguous_or_unknown_is_never_paid_and_never_cancels(?string $orderStatus, ?string $orderDetail, ?string $payStatus, ?string $payDetail): void
    {
        $mapped = OrderStatusMapper::map(self::order($orderStatus, $orderDetail, $payStatus, $payDetail));

        $this->assertNotSame(GatewayPaymentStatus::APPROVED, $mapped['status']);
        $this->assertFalse($mapped['status']->isDeclined(), 'na dúvida, não cancela (nem devolve estoque)');
        $this->assertNotSame(PaymentStatus::PAID, $mapped['status']->toPaymentStatus());
        $this->assertSame(GatewayPaymentStatus::IN_PROCESS, $mapped['status'], 'espera: continua sendo consultado');
    }

    public function test_unknown_values_are_flagged_and_contradictions_are_flagged(): void
    {
        $this->assertSame(OrderStatusMapper::NOTE_UNKNOWN, OrderStatusMapper::map(self::order('status_novo', 'x', null, null))['note']);
        $this->assertSame(OrderStatusMapper::NOTE_UNKNOWN, OrderStatusMapper::map(self::order('processed', 'accredited_late', 'processed', 'accredited_late'))['note']);
        $this->assertSame(OrderStatusMapper::NOTE_CONFLICT, OrderStatusMapper::map(self::order('failed', 'failed', 'processed', 'accredited'))['note']);
    }

    public function test_approval_needs_both_levels(): void
    {
        $this->assertSame(GatewayPaymentStatus::APPROVED, OrderStatusMapper::map(self::order('processed', 'accredited', 'processed', 'accredited'))['status']);
        // Só a order (sem bloco de transação) aprovada também vale: a transação é opcional no corpo.
        $this->assertSame(GatewayPaymentStatus::APPROVED, OrderStatusMapper::map(self::order('processed', 'accredited', null, null))['status']);
    }

    public function test_a_refund_on_an_approved_transaction_follows_the_order(): void
    {
        $this->assertSame(GatewayPaymentStatus::REFUNDED, OrderStatusMapper::map(self::order('refunded', 'refunded', 'processed', 'accredited'))['status']);
        $this->assertSame(GatewayPaymentStatus::CHARGED_BACK, OrderStatusMapper::map(self::order('charged_back', 'settled', 'processed', 'accredited'))['status']);
        $this->assertSame(GatewayPaymentStatus::IN_MEDIATION, OrderStatusMapper::map(self::order('charged_back', 'in_process', 'processed', 'accredited'))['status']);
    }

    /** @return array<string, mixed> */
    private static function order(?string $orderStatus, ?string $orderDetail, ?string $payStatus, ?string $payDetail): array
    {
        $order = array_filter(['status' => $orderStatus, 'status_detail' => $orderDetail], fn ($v) => $v !== null);

        if ($payStatus !== null || $payDetail !== null) {
            $order['transactions'] = ['payments' => [array_filter(['status' => $payStatus, 'status_detail' => $payDetail], fn ($v) => $v !== null)]];
        }

        return $order;
    }
}

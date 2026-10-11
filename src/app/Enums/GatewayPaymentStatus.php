<?php

namespace App\Enums;

/**
 * Status BRUTO de um pagamento no Mercado Pago (`payments.status`).
 *
 * Por que NÃO é cast de `Payment::status` nem tem CHECK no banco: o conjunto é do
 * gateway, não nosso. Um valor novo do MP faria o cast lançar `ValueError` (ou o
 * CHECK rejeitar o INSERT) justamente ao registrar um pagamento real. A coluna
 * continua string; este enum serve para interpretar o valor (`tryFrom`, que
 * devolve null para desconhecidos), rotular e mapear para {@see PaymentStatus}.
 */
enum GatewayPaymentStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case AUTHORIZED = 'authorized';
    case IN_PROCESS = 'in_process';
    case IN_MEDIATION = 'in_mediation';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case CHARGED_BACK = 'charged_back';

    // Estados LOCAIS (E3.4), nunca vindos do gateway: a tentativa é registrada como
    // `created` antes da chamada externa e vira `failed` se não houve resposta utilizável.
    case CREATED = 'created';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::APPROVED => 'Aprovado',
            self::AUTHORIZED => 'Autorizado',
            self::IN_PROCESS => 'Processando',
            self::IN_MEDIATION => 'Em mediação',
            self::REJECTED => 'Rejeitado',
            self::CANCELLED => 'Cancelado',
            self::REFUNDED => 'Reembolsado',
            self::CHARGED_BACK => 'Estornado',
            self::CREATED => 'Registrado',
            self::FAILED => 'Falhou',
        };
    }

    /** Cor Bootstrap (D8): nunca `primary` nem `danger`. */
    public function variant(): string
    {
        return match ($this) {
            self::APPROVED => 'success',
            self::PENDING, self::AUTHORIZED, self::IN_PROCESS, self::CREATED => 'warning',
            self::IN_MEDIATION => 'info',
            self::REJECTED, self::CANCELLED, self::REFUNDED, self::CHARGED_BACK, self::FAILED => 'secondary',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING || $this === self::IN_PROCESS || $this === self::CREATED;
    }

    /** O gateway recusou/cancelou a cobrança: não há dinheiro a receber. */
    public function isDeclined(): bool
    {
        return $this === self::REJECTED || $this === self::CANCELLED;
    }

    /**
     * Efeito no `orders.payment_status`; null = o status do gateway não altera o pedido.
     */
    public function toPaymentStatus(): ?PaymentStatus
    {
        return match ($this) {
            self::APPROVED => PaymentStatus::PAID,
            self::PENDING, self::IN_PROCESS => PaymentStatus::UNPAID,
            self::REFUNDED, self::CHARGED_BACK => PaymentStatus::REFUNDED,
            default => null,
        };
    }
}

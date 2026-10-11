<?php

namespace App\Enums;

/**
 * Situação financeira do PEDIDO (`orders.payment_status`). Os valores espelham o
 * CHECK `orders_payment_status_valid`.
 *
 * Não é o status do gateway: esse é {@see GatewayPaymentStatus}
 * (`payments.status`, valor bruto do Mercado Pago).
 */
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PAID = 'paid';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::UNPAID => 'Não pago',
            self::PAID => 'Pago',
            self::REFUNDED => 'Reembolsado',
        };
    }

    /** Cor Bootstrap (D8): nunca `primary` nem `danger`. */
    public function variant(): string
    {
        return match ($this) {
            self::UNPAID => 'warning',
            self::PAID => 'success',
            self::REFUNDED => 'secondary',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

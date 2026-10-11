<?php

namespace App\Enums;

/**
 * Status do pedido (`orders.status`). Os valores espelham o CHECK
 * `orders_status_valid` da migration.
 */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::CONFIRMED => 'Confirmado',
            self::DONE => 'Concluído',
            self::CANCELLED => 'Cancelado',
        };
    }

    /**
     * Cor Bootstrap (D8). Nunca `primary` (o tenant sobrescreve) nem `danger`
     * (reservado a ações destrutivas).
     */
    public function variant(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'info',
            self::DONE => 'success',
            self::CANCELLED => 'secondary',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

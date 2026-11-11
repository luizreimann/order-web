<?php

namespace App\Enums;

/**
 * Como o cliente recebe o pedido (`orders.fulfillment_type`). Cada loja liga/desliga cada uma.
 */
enum FulfillmentType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Retirada',
            self::Delivery => 'Entrega',
        };
    }

    public function isDelivery(): bool
    {
        return $this === self::Delivery;
    }
}

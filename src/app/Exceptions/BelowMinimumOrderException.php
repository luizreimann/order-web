<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * Subtotal abaixo do pedido mínimo da loja (vale para retirada e entrega, antes de qualquer
 * taxa). Lançada antes de gravar qualquer coisa.
 */
class BelowMinimumOrderException extends RuntimeException
{
    public function __construct(public readonly int $minimumCents, public readonly int $shortfallCents)
    {
        parent::__construct('Pedido mínimo de R$ '.Money::format($minimumCents).'. Faltam R$ '.Money::format($shortfallCents).'.');
    }
}

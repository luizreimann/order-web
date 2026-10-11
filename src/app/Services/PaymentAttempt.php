<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

/**
 * Pedido + tentativa de pagamento registrados por {@see PaymentService::begin()}.
 * `duplicate = true`: a tentativa JÁ existia (duplo submit) e nada novo foi criado.
 */
final class PaymentAttempt
{
    private function __construct(
        public readonly Order $order,
        public readonly Payment $payment,
        public readonly bool $duplicate,
    ) {}

    public static function fresh(Order $order, Payment $payment): self
    {
        return new self($order, $payment, false);
    }

    public static function duplicate(Order $order, Payment $payment): self
    {
        return new self($order, $payment, true);
    }
}

<?php

namespace App\Services;

use App\Contracts\PaymentResult;
use App\Models\Order;
use App\Models\Payment;

/**
 * Resultado de {@see PaymentService::charge()}.
 *
 *  - SETTLED: cobrança aceita (aprovada ou pendente) e consolidada no banco;
 *  - FAILED: cobrança não aconteceu/foi recusada; pedido cancelado e estoque devolvido;
 *  - UNCONSOLIDATED: o gateway ACEITOU a cobrança mas não conseguimos gravar o resultado
 *    (incidente registrado; o webhook concilia). O cliente pagou: não é falha.
 */
final class ChargeOutcome
{
    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    public const UNCONSOLIDATED = 'unconsolidated';

    public function __construct(
        public readonly string $state,
        public readonly Order $order,
        public readonly Payment $payment,
        public readonly PaymentResult $result,
        public readonly ?string $message = null,
    ) {}

    public function failed(): bool
    {
        return $this->state === self::FAILED;
    }
}

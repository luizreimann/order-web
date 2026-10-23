<?php

namespace App\Contracts;

/**
 * Resultado de {@see CancelsPayments::cancelPayment()}.
 *
 *  - CANCELED: o provedor cancelou agora;
 *  - ALREADY_CANCELED: já estava cancelado (conflito idempotente: tratar como cancelado);
 *  - NOT_CANCELABLE: o provedor recusou porque o estado mudou (ex.: foi pago no último
 *    instante); `currentStatus` traz o estado informado, se houver. Quem chama deve RECONSULTAR;
 *  - UNAVAILABLE: rede, timeout, 5xx ou resposta que não se entende; NADA se sabe do estado.
 */
final class PaymentCancellation
{
    public const CANCELED = 'canceled';

    public const ALREADY_CANCELED = 'already_canceled';

    public const NOT_CANCELABLE = 'not_cancelable';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $currentStatus = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function canceled(): self
    {
        return new self(self::CANCELED);
    }

    public static function alreadyCanceled(): self
    {
        return new self(self::ALREADY_CANCELED, 'canceled', 409, 'order_already_canceled');
    }

    public static function notCancelable(?string $currentStatus = null, ?string $errorCode = null): self
    {
        return new self(self::NOT_CANCELABLE, $currentStatus, 409, $errorCode);
    }

    public static function unavailable(?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(self::UNAVAILABLE, null, $httpStatus, $errorCode);
    }

    /** O provedor confirma que NÃO há mais cobrança a receber por esta via. */
    public function isCanceled(): bool
    {
        return $this->outcome === self::CANCELED || $this->outcome === self::ALREADY_CANCELED;
    }
}

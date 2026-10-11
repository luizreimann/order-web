<?php

namespace App\Contracts;

/**
 * Notificação de gateway já interpretada ({@see PaymentGatewayInterface::parseWebhook()}).
 * Imutável e sem I/O: só diz O QUE o gateway quer avisar, nunca em que estado está.
 */
final class WebhookEvent
{
    private function __construct(
        public readonly ?string $paymentId,
        public readonly ?string $requestId,
        public readonly ?string $ignoredReason,
        public readonly ?string $invalidReason,
    ) {}

    public static function payment(string $paymentId, ?string $requestId = null): self
    {
        return new self($paymentId, $requestId !== '' ? $requestId : null, null, null);
    }

    /** Notificação legítima mas irrelevante (ex.: outro tipo de evento): 200, sem reenvio. */
    public static function ignored(string $reason): self
    {
        return new self(null, null, $reason, null);
    }

    /** Payload malformado. */
    public static function invalid(string $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function isActionable(): bool
    {
        return $this->paymentId !== null;
    }
}

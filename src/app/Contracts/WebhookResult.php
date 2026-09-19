<?php

namespace App\Contracts;

class WebhookResult
{
    public function __construct(
        public readonly bool $processed,
        public readonly ?string $orderId = null,
        public readonly ?string $paymentStatus = null,
        public readonly array $meta = [],
        public readonly ?string $errorMessage = null,
    ) {}

    public static function processed(string $orderId, string $paymentStatus, array $meta = []): self
    {
        return new self(
            processed: true,
            orderId: $orderId,
            paymentStatus: $paymentStatus,
            meta: $meta,
        );
    }

    public static function ignored(?string $reason = null): self
    {
        return new self(
            processed: false,
            errorMessage: $reason,
        );
    }

    public static function failed(string $errorMessage): self
    {
        return new self(
            processed: false,
            errorMessage: $errorMessage,
        );
    }
}

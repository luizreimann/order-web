<?php

namespace App\Contracts;

use App\Enums\GatewayPaymentStatus;

class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $paymentId = null,
        public readonly ?string $status = null,
        public readonly ?string $statusDetail = null,
        public readonly array $meta = [],
        public readonly ?string $errorMessage = null,
        public readonly ?int $errorCode = null,
    ) {}

    public static function success(
        string $paymentId,
        string $status,
        ?string $statusDetail = null,
        array $meta = []
    ): self {
        return new self(
            success: true,
            paymentId: $paymentId,
            status: $status,
            statusDetail: $statusDetail,
            meta: $meta,
        );
    }

    public static function failure(string $errorMessage, ?int $errorCode = null, array $meta = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            meta: $meta,
        );
    }

    /** Status bruto do gateway interpretado; null se desconhecido. */
    public function gatewayStatus(): ?GatewayPaymentStatus
    {
        return $this->status === null ? null : GatewayPaymentStatus::tryFrom($this->status);
    }

    public function isApproved(): bool
    {
        return $this->success && $this->gatewayStatus() === GatewayPaymentStatus::APPROVED;
    }

    /** O gateway respondeu, mas recusou/cancelou a cobrança (ex.: cartão rejeitado, HTTP 201). */
    public function isDeclined(): bool
    {
        return $this->success && ($this->gatewayStatus()?->isDeclined() ?? false);
    }

    public function isPending(): bool
    {
        return $this->success && ($this->gatewayStatus()?->isPending() ?? false);
    }
}

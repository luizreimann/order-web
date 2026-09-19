<?php

namespace App\Contracts;

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

    public function isApproved(): bool
    {
        return $this->success && $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->success && in_array($this->status, ['pending', 'in_process'], true);
    }
}

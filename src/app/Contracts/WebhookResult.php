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
        /** O estado do pagamento mudou NESTA entrega (false em reenvio/no-op). */
        public readonly bool $transitioned = false,
        /** Falha transitória: o endpoint responde 5xx para o gateway reenviar. */
        public readonly bool $retryable = false,
    ) {}

    public static function processed(string $orderId, string $paymentStatus, array $meta = []): self
    {
        return new self(
            processed: true,
            orderId: $orderId,
            paymentStatus: $paymentStatus,
            meta: $meta,
            transitioned: true,
        );
    }

    /** Reenvio/entrega concorrente: o estado já estava aplicado, nada foi alterado. */
    public static function unchanged(string $orderId, string $paymentStatus, array $meta = []): self
    {
        return new self(
            processed: true,
            orderId: $orderId,
            paymentStatus: $paymentStatus,
            meta: $meta,
            transitioned: false,
        );
    }

    public static function ignored(?string $reason = null): self
    {
        return new self(
            processed: false,
            errorMessage: $reason,
        );
    }

    /** Falha permanente (ex.: payload inválido): reenviar não resolveria. */
    public static function failed(string $errorMessage): self
    {
        return new self(
            processed: false,
            errorMessage: $errorMessage,
        );
    }

    /** Falha transitória (ex.: API do gateway fora): pede reenvio. */
    public static function retry(string $errorMessage): self
    {
        return new self(
            processed: false,
            errorMessage: $errorMessage,
            retryable: true,
        );
    }
}

<?php

namespace App\Gateways\Concerns;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\WebhookResult;
use App\Models\Tenant;
use App\Services\PaymentWebhookProcessor;

/**
 * Implementação padrão de `PaymentGatewayInterface::handleWebhook()`: toda a lógica
 * (lookup, consulta à API, lock, idempotência, transição) é do
 * {@see PaymentWebhookProcessor}, igual para qualquer gateway.
 *
 * @phpstan-require-implements PaymentGatewayInterface
 */
trait ProcessesWebhooks
{
    public function handleWebhook(array $payload, array $headers = [], ?Tenant $tenant = null): WebhookResult
    {
        return app(PaymentWebhookProcessor::class)->process($this, $payload, $headers, $tenant);
    }
}

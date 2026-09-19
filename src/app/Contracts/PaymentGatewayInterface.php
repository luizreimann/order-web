<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Tenant;

interface PaymentGatewayInterface
{
    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult;

    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult;

    public function handleWebhook(array $payload): WebhookResult;
}

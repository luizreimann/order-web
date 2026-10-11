<?php

namespace Tests\Support\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\WebhookEvent;
use App\Enums\PaymentMethod;
use App\Gateways\Concerns\ProcessesWebhooks;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Segundo gateway, SÓ de teste (E3.5 / D4): prova que checkout e webhook funcionam sem
 * tocar uma linha do Mercado Pago. Implementa a interface inteira em memória: nada de
 * HTTP, nada de credenciais reais.
 *
 * Registro (apenas em teste): `app(PaymentGatewayManager::class)->extend('fake', fn () => $fake)`.
 *
 * Comportamento controlável: `$nextStatus` (status devolvido por `createPayment`),
 * `$throwOnCreate`, e `$remote` (o "estado no provedor" que `getPayment` consulta).
 *
 * Assinatura do webhook: header `x-fake-signature` = HMAC-SHA256 do campo `id` com o
 * `settings.payment.fake.secret` do tenant.
 */
class FakeGateway implements PaymentGatewayInterface
{
    use ProcessesWebhooks;

    /** @var list<array{order_id: string, amount_cents: int, idempotency_key: ?string, tenant_id: string}> */
    public array $charges = [];

    /** @var array<string, array{status: string, order_id: string, tenant_id: string}> */
    public array $remote = [];

    public string $nextStatus = 'pending';

    public ?\Throwable $throwOnCreate = null;

    private int $sequence = 0;

    public function key(): string
    {
        return 'fake';
    }

    public function isConfigured(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'payment.fake.secret');
    }

    public function checkoutPayload(Tenant $tenant, PaymentMethod $method, int $totalCents, string $processPaymentUrl): array
    {
        return [
            'publicKey' => 'fake-public-key',
            'processPaymentUrl' => $processPaymentUrl,
            'amount' => round($totalCents / 100, 2),
            'mpMethod' => $method->value,
            'allowed' => [],
        ];
    }

    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult
    {
        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        $this->charges[] = [
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'tenant_id' => $tenant->id,
        ];

        $id = 'fake-'.(++$this->sequence);
        $this->remote[$id] = ['status' => $this->nextStatus, 'order_id' => $order->id, 'tenant_id' => $tenant->id];

        return PaymentResult::success($id, $this->nextStatus, null, ['payment_id' => $id]);
    }

    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult
    {
        $remote = $this->remote[$paymentId] ?? null;

        if ($remote === null) {
            return null;
        }

        return PaymentResult::success($paymentId, $remote['status'], null, [
            'payment_id' => $paymentId,
            'metadata' => ['tenant_id' => $remote['tenant_id'], 'order_id' => $remote['order_id']],
        ]);
    }

    public static function signature(string $id, string $secret): string
    {
        return hash_hmac('sha256', $id, $secret);
    }

    public function validateWebhook(Tenant $tenant, array $payload, array $headers): bool
    {
        $secret = data_get($tenant->settings, 'payment.fake.secret');
        $signature = $headers['x-fake-signature'] ?? '';

        return is_string($secret) && $secret !== ''
            && hash_equals(self::signature((string) ($payload['id'] ?? ''), $secret), $signature);
    }

    public function parseWebhook(array $payload, array $headers): WebhookEvent
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return WebhookEvent::invalid('id ausente');
        }

        return WebhookEvent::payment($id, $headers['x-fake-delivery'] ?? null);
    }
}

<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\WebhookResult;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    protected const API_BASE_URL = 'https://api.mercadopago.com/v1';

    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken) {
            return PaymentResult::failure('Credenciais do Mercado Pago não configuradas.');
        }

        $formData = $data['formData'] ?? $data;
        $customerEmail = $data['customer_email'] ?? null;

        $transactionAmount = round($order->total_cents / 100, 2);

        if (isset($formData['installments'])) {
            $formData['installments'] = 1;
        }

        $formData['transaction_amount'] = $transactionAmount;
        $formData['description'] = 'Pedido #'.$order->number;
        $formData['external_reference'] = $order->id;

        $formData['metadata'] = array_merge((array) ($formData['metadata'] ?? []), [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'order_id' => $order->id,
        ]);

        $formData['notification_url'] = url('/webhooks/mercadopago');

        $payer = (array) ($formData['payer'] ?? []);
        if ((! isset($payer['email']) || trim($payer['email']) === '') && $customerEmail) {
            $payer['email'] = $customerEmail;
            $formData['payer'] = $payer;
        }

        $idempotencyKey = (string) Str::uuid();

        $response = $this->makeRequest(
            $accessToken,
            'POST',
            '/payments',
            $formData,
            ['X-Idempotency-Key' => $idempotencyKey]
        );

        if (! $response instanceof Response) {
            return PaymentResult::failure('Falha na comunicação com Mercado Pago.');
        }

        if (! $response->successful()) {
            return PaymentResult::failure(
                'Falha ao processar pagamento.',
                $response->status(),
                ['error' => $response->json()]
            );
        }

        $mpData = $response->json();

        $gatewayPaymentId = (string) data_get($mpData, 'id');
        $status = (string) data_get($mpData, 'status');
        $statusDetail = data_get($mpData, 'status_detail');

        Payment::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => $gatewayPaymentId,
            'amount_cents' => $order->total_cents,
            'status' => $status,
            'status_detail' => $statusDetail,
            'meta' => [
                'idempotency_key' => $idempotencyKey,
                'payment_method_id' => data_get($mpData, 'payment_method_id'),
                'point_of_interaction' => data_get($mpData, 'point_of_interaction'),
            ],
        ]);

        return PaymentResult::success(
            paymentId: $gatewayPaymentId,
            status: $status,
            statusDetail: $statusDetail,
            meta: [
                'idempotency_key' => $idempotencyKey,
                'payment_id' => $gatewayPaymentId,
                'status' => $status,
                'status_detail' => $statusDetail,
                'payment_method_id' => data_get($mpData, 'payment_method_id'),
                'point_of_interaction' => data_get($mpData, 'point_of_interaction'),
            ]
        );
    }

    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken) {
            return null;
        }

        $response = $this->makeRequest($accessToken, 'GET', '/payments/'.$paymentId);

        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $mpData = $response->json();

        return PaymentResult::success(
            paymentId: (string) data_get($mpData, 'id'),
            status: (string) data_get($mpData, 'status'),
            statusDetail: data_get($mpData, 'status_detail'),
            meta: [
                'payment_id' => data_get($mpData, 'id'),
                'status' => data_get($mpData, 'status'),
                'status_detail' => data_get($mpData, 'status_detail'),
                'payment_method_id' => data_get($mpData, 'payment_method_id'),
                'point_of_interaction' => data_get($mpData, 'point_of_interaction'),
                'metadata' => data_get($mpData, 'metadata'),
            ]
        );
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        $type = (string) ($payload['type'] ?? $payload['topic'] ?? '');
        $paymentId = $payload['data']['id'] ?? $payload['id'] ?? null;

        if ($type !== '' && ! in_array($type, ['payment', 'payments'], true)) {
            return WebhookResult::ignored('Tipo de notificação não suportado: '.$type);
        }

        if (! is_numeric($paymentId) && ! (is_string($paymentId) && $paymentId !== '')) {
            return WebhookResult::failed('Payload inválido: payment_id ausente.');
        }

        $paymentId = (string) $paymentId;

        // CROSS-TENANT DELIBERADO: o webhook chega sem tenant no contexto
        // (rota fora do prefixo `/{tenant:slug}`). Sem este opt-out explícito
        // o pedido não seria encontrado e o pagamento nunca seria confirmado.
        $order = Order::withoutTenantScope()
            ->where('meta->mercadopago->payment_id', $paymentId)
            ->first();

        if (! $order) {
            return WebhookResult::ignored('Pedido não encontrado para payment_id: '.$paymentId);
        }

        $tenant = Tenant::query()->where('id', $order->tenant_id)->first();
        if (! $tenant) {
            return WebhookResult::ignored('Tenant não encontrado.');
        }

        $paymentResult = $this->getPayment($tenant, $paymentId);
        if (! $paymentResult) {
            return WebhookResult::failed('Não foi possível consultar pagamento no Mercado Pago.');
        }

        $metaTenantId = (string) data_get($paymentResult->meta, 'metadata.tenant_id');
        $metaOrderId = (string) data_get($paymentResult->meta, 'metadata.order_id');

        if (($metaTenantId !== '' && $metaTenantId !== (string) $order->tenant_id)
            || ($metaOrderId !== '' && $metaOrderId !== (string) $order->id)) {
            return WebhookResult::ignored('Metadata não corresponde ao pedido.');
        }

        $order->meta = array_merge($order->meta ?? [], [
            'mercadopago' => array_merge((array) data_get($order->meta, 'mercadopago', []), [
                'status' => $paymentResult->status,
                'status_detail' => $paymentResult->statusDetail,
                'payment_method_id' => data_get($paymentResult->meta, 'payment_method_id'),
                'point_of_interaction' => data_get($paymentResult->meta, 'point_of_interaction'),
                'last_webhook_at' => now()->toIso8601String(),
            ]),
        ]);

        $paymentStatus = $this->mapPaymentStatus($paymentResult->status);
        if ($paymentStatus) {
            $order->payment_status = $paymentStatus;
        }

        $order->save();

        // CROSS-TENANT DELIBERADO: mesmo motivo do lookup do pedido acima.
        // O par (gateway, gateway_payment_id) já é globalmente único.
        $payment = Payment::withoutTenantScope()
            ->where('gateway', 'mercadopago')
            ->where('gateway_payment_id', $paymentId)
            ->first();

        if ($payment) {
            $payment->update([
                'status' => $paymentResult->status,
                'status_detail' => $paymentResult->statusDetail,
                'meta' => array_merge($payment->meta ?? [], [
                    'payment_method_id' => data_get($paymentResult->meta, 'payment_method_id'),
                    'point_of_interaction' => data_get($paymentResult->meta, 'point_of_interaction'),
                    'last_webhook_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        return WebhookResult::processed(
            orderId: $order->id,
            paymentStatus: $order->payment_status->value,
            meta: $paymentResult->meta
        );
    }

    protected function mapPaymentStatus(?string $mpStatus): ?PaymentStatus
    {
        return $mpStatus === null ? null : GatewayPaymentStatus::tryFrom($mpStatus)?->toPaymentStatus();
    }

    public function validateWebhookSignature(string $dataId, array $headers, ?string $secretKey): bool
    {
        if (! $secretKey || $secretKey === '') {
            if (app()->environment('local', 'development', 'testing')) {
                return true;
            }
            Log::warning('MercadoPago webhook: secret key not configured');

            return false;
        }

        $xSignature = $headers['x-signature'] ?? $headers['X-Signature'] ?? '';
        $xRequestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? '';

        if ($xSignature === '') {
            Log::warning('MercadoPago webhook: x-signature header missing');

            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ($ts === '' || $v1 === '') {
            Log::warning('MercadoPago webhook: invalid x-signature format');

            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expectedHash = hash_hmac('sha256', $manifest, $secretKey);

        if (! hash_equals($expectedHash, $v1)) {
            Log::warning('MercadoPago webhook: signature mismatch');

            return false;
        }

        return true;
    }

    public function getWebhookSecret(Tenant $tenant): ?string
    {
        $mpSettings = (array) data_get($tenant->settings, 'payment.mercadopago', []);

        return EncryptionHelper::decryptIfNeeded(data_get($mpSettings, 'webhook_secret'));
    }

    public function testConnection(Tenant $tenant): bool
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken) {
            return false;
        }

        $response = $this->makeRequest($accessToken, 'GET', '/payment_methods');

        return $response instanceof Response && $response->successful();
    }

    protected function getAccessToken(Tenant $tenant): ?string
    {
        $mpSettings = (array) data_get($tenant->settings, 'payment.mercadopago', []);

        return EncryptionHelper::decryptIfNeeded(data_get($mpSettings, 'access_token'));
    }

    protected function makeRequest(
        string $accessToken,
        string $method,
        string $endpoint,
        array $data = [],
        array $extraHeaders = []
    ) {
        $integratorId = (string) config('services.mercadopago.integrator_id');

        $headers = array_merge([
            'Accept' => 'application/json',
        ], $extraHeaders);

        if ($integratorId !== '') {
            $headers['X-Integrator-Id'] = $integratorId;
        }

        $request = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->timeout(30)
            ->connectTimeout(10);

        $url = self::API_BASE_URL.$endpoint;

        try {
            return match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'DELETE' => $request->delete($url, $data),
                default => null,
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return null;
        }
    }
}

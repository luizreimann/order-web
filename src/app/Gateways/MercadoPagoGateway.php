<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\WebhookEvent;
use App\Enums\PaymentMethod;
use App\Gateways\Concerns\ProcessesWebhooks;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use App\Support\WebhookSecurity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gateway Mercado Pago. Só fala com a API do MP: não persiste nada. A tentativa de
 * `Payment` é registrada ANTES da chamada e consolidada DEPOIS por `PaymentService`;
 * a idempotência e o lock do webhook vivem em `PaymentWebhookProcessor` (E3.3/E3.4).
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{
    use ProcessesWebhooks;

    protected const API_BASE_URL = 'https://api.mercadopago.com/v1';

    public function key(): string
    {
        return 'mercadopago';
    }

    public function isConfigured(Tenant $tenant): bool
    {
        $publicKey = data_get($tenant->settings, 'payment.mercadopago.public_key');

        return ! empty($publicKey) && $this->getAccessToken($tenant) !== null;
    }

    public function checkoutPayload(Tenant $tenant, PaymentMethod $method, int $totalCents, string $processPaymentUrl): array
    {
        $allowed = [
            'creditCard' => null,
            'debitCard' => null,
            'bankTransfer' => null,
        ];

        match ($method) {
            PaymentMethod::MP_CREDIT_CARD => $allowed['creditCard'] = 'all',
            PaymentMethod::MP_DEBIT_CARD => $allowed['debitCard'] = 'all',
            PaymentMethod::MP_PIX => $allowed['bankTransfer'] = ['pix'],
            default => null,
        };

        return [
            'publicKey' => data_get($tenant->settings, 'payment.mercadopago.public_key'),
            'processPaymentUrl' => $processPaymentUrl,
            'amount' => round($totalCents / 100, 2),
            'mpMethod' => $method->value,
            'allowed' => $allowed,
        ];
    }

    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken) {
            return PaymentResult::failure('Credenciais do Mercado Pago não configuradas.');
        }

        // Sem `formData`, o resto de `$data` é o corpo; as chaves de controle não vão ao MP.
        $formData = $data['formData'] ?? Arr::except($data, ['customer_email', 'idempotency_key']);
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

        // O id do tenant na URL é o que permite autenticar o webhook com o secret CERTO
        // antes de olhar qualquer dado do payload (E3.3). O id (e não o slug) porque o
        // slug é editável e a URL já foi entregue ao MP.
        $formData['notification_url'] = route('webhooks.tenant', [
            'gateway' => $this->key(),
            'tenantId' => $tenant->id,
        ]);

        $payer = (array) ($formData['payer'] ?? []);
        if ((! isset($payer['email']) || trim($payer['email']) === '') && $customerEmail) {
            $payer['email'] = $customerEmail;
            $formData['payer'] = $payer;
        }

        // A chave da tentativa persistida pelo chamador; sem ela (uso avulso) gera uma.
        $idempotencyKey = (string) ($data['idempotency_key'] ?? Str::uuid());

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

    public function parseWebhook(array $payload, array $headers): WebhookEvent
    {
        $type = (string) ($payload['type'] ?? $payload['topic'] ?? '');
        $paymentId = data_get($payload, 'data.id') ?? ($payload['id'] ?? null);

        if ($type !== '' && ! in_array($type, ['payment', 'payments'], true)) {
            return WebhookEvent::ignored('Tipo de notificação não suportado: '.$type);
        }

        if (! is_numeric($paymentId) && ! (is_string($paymentId) && $paymentId !== '')) {
            return WebhookEvent::invalid('Payload inválido: payment_id ausente.');
        }

        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? null;

        return WebhookEvent::payment((string) $paymentId, is_string($requestId) ? $requestId : null);
    }

    public function validateWebhook(Tenant $tenant, array $payload, array $headers): bool
    {
        $dataId = (string) (data_get($payload, 'data.id') ?? ($payload['id'] ?? ''));

        return $this->validateWebhookSignature($dataId, $headers, $this->getWebhookSecret($tenant));
    }

    public function validateWebhookSignature(string $dataId, array $headers, ?string $secretKey): bool
    {
        if (! $secretKey || $secretKey === '') {
            // Bypass SOMENTE em local/testing; qualquer outro ambiente falha fechado.
            if (WebhookSecurity::secretlessBypassAllowed()) {
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

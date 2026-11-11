<?php

namespace App\Gateways;

use App\Contracts\CancelsPayments;
use App\Contracts\PaymentCancellation;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Contracts\ReportsMissingPayments;
use App\Contracts\ValidatesPaymentIds;
use App\Contracts\WebhookEvent;
use App\Enums\PaymentMethod;
use App\Gateways\Concerns\ProcessesWebhooks;
use App\Gateways\MercadoPago\OrderStatusMapper;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\PaymentFailure;
use App\Support\DeviceSessionId;
use App\Support\EncryptionHelper;
use App\Support\PixExpiration;
use App\Support\PixWindow;
use App\Support\WebhookSecurity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Gateway Mercado Pago sobre a API de ORDERS (`/v1/orders`, Checkout Transparente).
 *
 * Só fala com a API do MP: não persiste nada. A tentativa de `Payment` é registrada ANTES da
 * chamada e consolidada DEPOIS por `PaymentService`; a idempotência e o lock do webhook vivem
 * em `PaymentWebhookProcessor` (E3.3/E3.4).
 *
 * Fatos provados na sandbox real (ver `.sprint/06-refactor/05-development.md`, §15):
 *  - `POST /v1/orders` não aceita `metadata` nem `notification_url` (400 `unsupported_properties`
 *    para metadata; a URL de notificação de Orders é só a do painel): o vínculo com o pedido
 *    é o `external_reference` (id do pedido, UUID de 36 caracteres);
 *  - cartão recusado responde HTTP 402 com `errors[]` E a order em `data` (status `failed`):
 *    é uma order criada e recusada, não um erro de requisição;
 *  - PIX nasce `processing/in_process` sem QR e (nesta conta de teste) nunca avançou; a
 *    produção, segundo a documentação, devolve `action_required/waiting_transfer` com o QR.
 *
 * Identificadores: o id que o sistema guarda e consulta é o da ORDER (`ORD...`); o da
 * transação (`PAY...`) vai em `meta`.
 */
class MercadoPagoGateway implements CancelsPayments, PaymentGatewayInterface, ReportsMissingPayments, ValidatesPaymentIds
{
    use ProcessesWebhooks;

    protected const API_BASE_URL = 'https://api.mercadopago.com/v1';

    /** Espera (s) entre consultas ao aguardar o QR do Pix logo após criar a order. */
    private const QR_WAIT_STEPS = [1, 1, 2, 2, 2];

    /** `ORD` + id alfanumérico (sandbox: `ORDTST...`). Ids numéricos são da API antiga de Payments. */
    private const ORDER_ID_PATTERN = '/\AORD[A-Za-z0-9]{10,64}\z/';

    /** `payment_method.id` aceito do navegador: bandeira/id curto (`master`, `visa`, `debelo`...). */
    private const CARD_METHOD_ID_PATTERN = '/\A[a-z][a-z0-9_]{1,29}\z/';

    public function key(): string
    {
        return 'mercadopago';
    }

    public function isConfigured(Tenant $tenant): bool
    {
        $publicKey = data_get($tenant->settings, 'payment.mercadopago.public_key');

        return ! empty($publicKey) && $this->getAccessToken($tenant) !== null;
    }

    public function isLookupableId(string $paymentId): bool
    {
        return preg_match(self::ORDER_ID_PATTERN, $paymentId) === 1;
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
            // `direct`: o servidor cria o pagamento sozinho (PIX: QR direto, sem formulário do
            // SDK). `card`: o navegador tokeniza o cartão no Card Payment Brick; os dados do
            // cartão nunca passam pelo servidor.
            'flow' => $method === PaymentMethod::MP_PIX ? 'direct' : 'card',
            // Prazo do Pix no MP (s), teto de segurança do lado dele (mínimo 30 min).
            'pixExpirationSeconds' => $method === PaymentMethod::MP_PIX ? PixExpiration::seconds() : null,
            // Janela em que o cliente pode pagar (s): regra do nosso servidor (PixWindow). O front
            // a usa no texto e no limite do polling; o contador vem do prazo ABSOLUTO do servidor.
            'pixWindowSeconds' => $method === PaymentMethod::MP_PIX ? PixWindow::seconds() : null,
            // Tipos que o Card Payment Brick deve EXCLUIR para só oferecer o método escolhido.
            'excludedTypes' => match ($method) {
                PaymentMethod::MP_CREDIT_CARD => ['debit_card'],
                PaymentMethod::MP_DEBIT_CARD => ['credit_card', 'prepaid_card'],
                default => [],
            },
        ];
    }

    /**
     * Corpo da order, montado AQUI (e não aceito do navegador):
     *  - PIX: `payment_method = {id: pix, type: bank_transfer}` + `expiration_time`;
     *  - cartão: `{id, type, token, installments: 1}`. O `id` (bandeira) vem do Brick, validado;
     *    o `type` (`credit_card`|`debit_card`) vem do MÉTODO escolhido no checkout, nunca do
     *    navegador. Sem token não há cobrança.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null null = dados insuficientes (cartão sem token/bandeira)
     */
    private function buildOrderBody(Order $order, PaymentMethod $method, array $data): ?array
    {
        $client = (array) ($data['formData'] ?? []);
        $amount = number_format($order->total_cents / 100, 2, '.', '');

        $payer = ['email' => trim((string) ($data['customer_email'] ?? ''))];
        $payer = array_filter($payer + $this->payerNames((string) ($data['customer_name'] ?? '')), fn ($v) => $v !== '');

        $payment = ['amount' => $amount];

        if ($method === PaymentMethod::MP_PIX) {
            $payment['payment_method'] = ['id' => 'pix', 'type' => 'bank_transfer'];
            $payment['expiration_time'] = PixExpiration::iso();
        } else {
            $token = $client['token'] ?? null;
            $methodId = $client['payment_method_id'] ?? null;

            if (! is_string($token) || trim($token) === '' || ! is_string($methodId) || preg_match(self::CARD_METHOD_ID_PATTERN, $methodId) !== 1) {
                return null;
            }

            $payment['payment_method'] = [
                'id' => $methodId,
                'type' => $method === PaymentMethod::MP_DEBIT_CARD ? 'debit_card' : 'credit_card',
                'token' => $token,
                'installments' => 1,
            ];

            $identification = (array) data_get($client, 'payer.identification', []);
            if (is_string($identification['type'] ?? null) && is_scalar($identification['number'] ?? null)) {
                $payer['identification'] = [
                    'type' => $identification['type'],
                    'number' => (string) $identification['number'],
                ];
            }
        }

        return [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => $amount,
            'external_reference' => (string) $order->id,
            'description' => 'Pedido #'.$order->number,
            'payer' => $payer,
            'transactions' => ['payments' => [$payment]],
        ];
    }

    /**
     * `first_name`/`last_name` a partir do nome do formulário: a primeira palavra e o resto.
     * Nada é inventado: nome de uma palavra só envia só `first_name`; vazio não envia nada.
     *
     * @return array{first_name?: string, last_name?: string}
     */
    private function payerNames(string $fullName): array
    {
        $clean = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $fullName));

        if ($clean === '') {
            return [];
        }

        $parts = explode(' ', $clean, 2);
        $names = ['first_name' => mb_substr($parts[0], 0, 60)];

        if (isset($parts[1]) && $parts[1] !== '') {
            $names['last_name'] = mb_substr($parts[1], 0, 60);
        }

        return $names;
    }

    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken) {
            return PaymentResult::failure('Credenciais do Mercado Pago não configuradas.', null, ['reason' => PaymentFailure::REASON_CONFIGURATION]);
        }

        $method = PaymentMethod::tryFrom((string) ($data['payment_method'] ?? ''));

        if ($method === null || ! $method->isMercadoPago()) {
            return PaymentResult::failure('Método de pagamento inválido para o Mercado Pago.', null, ['error' => 'invalid_payment_method']);
        }

        $body = $this->buildOrderBody($order, $method, $data);

        if ($body === null) {
            return PaymentResult::failure('Dados do cartão ausentes.', null, ['error' => 'missing_card_token']);
        }

        // A chave da tentativa persistida pelo chamador; sem ela gera uma (a API exige).
        $idempotencyKey = (string) ($data['idempotency_key'] ?? Str::uuid());
        $headers = ['X-Idempotency-Key' => $idempotencyKey];

        // Device ID (antifraude): validado de novo aqui; inválido/ausente = segue sem ele.
        $deviceId = DeviceSessionId::sanitize($data['device_session_id'] ?? null);
        if ($deviceId !== null) {
            $headers['X-meli-session-id'] = $deviceId;
        }

        $response = $this->makeRequest($accessToken, 'POST', '/orders', $body, $headers);

        if (! $response instanceof Response) {
            return PaymentResult::failure('Falha na comunicação com Mercado Pago.', null, ['reason' => PaymentFailure::REASON_UNREACHABLE]);
        }

        $json = (array) $response->json();

        // Cartão recusado: HTTP 402 `failed` com a order criada em `data`. Há id e estado
        // final (`failed`): tratado como recusa registrada, para a compensação gravar o id.
        if ($response->status() === 402 && is_string(data_get($json, 'data.id')) && data_get($json, 'data.id') !== '') {
            return $this->resultFromOrder((array) $json['data'], $idempotencyKey);
        }

        if (! $response->successful()) {
            $error = array_intersect_key($json, array_flip(['errors', 'error', 'message', 'status']));

            return PaymentResult::failure(
                'Falha ao processar pagamento.',
                $response->status(),
                array_filter([
                    'error' => $error !== [] ? $error : null,
                    'reason' => PaymentFailure::hasConfigurationCode($json) ? PaymentFailure::REASON_CONFIGURATION : null,
                ], fn ($v) => $v !== null)
            );
        }

        if (! is_string($json['id'] ?? null) || $json['id'] === '') {
            return PaymentResult::failure('Resposta inesperada do Mercado Pago.', $response->status(), ['reason' => PaymentFailure::REASON_UNREACHABLE]);
        }

        // PIX assíncrono: a order nasce `processing` e o QR pode demorar. Espera CURTA e
        // limitada; sem QR, o checkout mostra "Gerando o seu Pix…" e o polling o obtém.
        if ($method === PaymentMethod::MP_PIX && $this->pixData($json) === null) {
            $json = $this->waitForPixQr($accessToken, $json);
        }

        return $this->resultFromOrder($json, $idempotencyKey);
    }

    /**
     * Consulta a order algumas vezes (no máximo `services.mercadopago.pix_qr_wait` segundos no
     * total; 0 desliga) até aparecer o QR ou a order sair de `processing`/`created`.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed> a última versão conhecida da order
     */
    private function waitForPixQr(string $accessToken, array $order): array
    {
        $budget = max(0, (float) config('services.mercadopago.pix_qr_wait', 8));
        $deadline = microtime(true) + $budget;

        foreach (self::QR_WAIT_STEPS as $step) {
            // Reserva ~0,7 s para a própria consulta: o orçamento é de relógio, não só de pausas.
            $pause = min($step, $deadline - microtime(true) - 0.7);

            if ($pause <= 0) {
                break;
            }

            Sleep::for($pause)->seconds();

            $response = $this->makeRequest($accessToken, 'GET', '/orders/'.$order['id']);

            if (! $response instanceof Response || ! $response->successful() || ! is_array($response->json())) {
                continue;
            }

            $order = (array) $response->json();

            if ($this->pixData($order) !== null || ! in_array($order['status'] ?? null, ['processing', 'created'], true)) {
                break;
            }
        }

        return $order;
    }

    /**
     * Dados do Pix de uma order (`qr_code`, `qr_code_base64`, `ticket_url`), ou null se ainda
     * não há nenhum.
     *
     * @param  array<string, mixed>  $order
     * @return array{qr_code?: string, qr_code_base64?: string, ticket_url?: string}|null
     */
    private function pixData(array $order): ?array
    {
        $pm = (array) data_get($order, 'transactions.payments.0.payment_method', []);

        $data = [];
        foreach (['qr_code', 'qr_code_base64', 'ticket_url'] as $field) {
            if (is_string($pm[$field] ?? null) && $pm[$field] !== '') {
                $data[$field] = $pm[$field];
            }
        }

        return $data === [] ? null : $data;
    }

    /**
     * Order → {@see PaymentResult} (status traduzido por {@see OrderStatusMapper}; o bruto do
     * MP fica em `meta.gateway_details`). O `paymentId` é o id da ORDER.
     *
     * @param  array<string, mixed>  $order
     */
    private function resultFromOrder(array $order, ?string $idempotencyKey = null): PaymentResult
    {
        $mapped = OrderStatusMapper::map($order);
        $payment = (array) data_get($order, 'transactions.payments.0', []);
        $pix = $this->pixData($order);

        if ($mapped['note'] !== null) {
            Log::warning('payment.gateway.status_not_in_table', [
                'gateway' => $this->key(),
                'note' => $mapped['note'],
                'order_status' => $mapped['raw']['order_status'],
                'order_status_detail' => $mapped['raw']['order_status_detail'],
                'payment_status' => $mapped['raw']['payment_status'],
                'payment_status_detail' => $mapped['raw']['payment_status_detail'],
            ]);
        }

        $externalReference = is_string($order['external_reference'] ?? null) ? $order['external_reference'] : null;

        return PaymentResult::success(
            paymentId: (string) $order['id'],
            status: $mapped['status']->value,
            statusDetail: $mapped['detail'],
            meta: array_filter([
                'idempotency_key' => $idempotencyKey,
                // `payment_id` = o id que o sistema guarda e consulta (o da ORDER); o da transação
                // (`PAY...`) é `transaction_id`.
                'payment_id' => (string) $order['id'],
                'transaction_id' => is_string($payment['id'] ?? null) ? $payment['id'] : null,
                'payment_method_id' => data_get($payment, 'payment_method.id'),
                'payment_method_type' => data_get($payment, 'payment_method.type'),
                'point_of_interaction' => $pix !== null ? ['transaction_data' => $pix] : null,
                // O vínculo com o pedido é o `external_reference` (Orders não aceita `metadata`).
                'metadata' => $externalReference !== null ? ['order_id' => $externalReference] : null,
                'gateway_details' => array_filter($mapped['raw'] + [
                    'order_id' => (string) $order['id'],
                    'transaction_id' => is_string($payment['id'] ?? null) ? $payment['id'] : null,
                    'note' => $mapped['note'],
                ], fn ($v) => $v !== null),
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * `GET /v1/orders/{id}` respondeu 404 com o código `order_not_found`: a order não existe para
     * estas credenciais. SÓ isso é definitivo; rede, timeout, 5xx, 401/403 e 404 com outro código
     * (ou sem código) não são.
     */
    public function paymentIsMissing(Tenant $tenant, string $paymentId): bool
    {
        $accessToken = $this->getAccessToken($tenant);

        if (! $accessToken || ! $this->isLookupableId($paymentId)) {
            return false;
        }

        $response = $this->makeRequest($accessToken, 'GET', '/orders/'.$paymentId);

        return $response instanceof Response
            && $response->status() === 404
            && collect((array) $response->json('errors'))->contains(fn ($e) => is_array($e) && ($e['code'] ?? null) === 'order_not_found');
    }

    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult
    {
        $accessToken = $this->getAccessToken($tenant);
        if (! $accessToken || ! $this->isLookupableId($paymentId)) {
            return null;
        }

        $response = $this->makeRequest($accessToken, 'GET', '/orders/'.$paymentId);

        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $order = $response->json();

        // 200 sem `status` (corpo inesperado/truncado): não é um estado. Devolver algo gravaria
        // uma "transição" para o vazio; null = "não consegui consultar" (o webhook pede
        // reenvio e a reconciliação tenta de novo).
        if (! is_array($order) || ! is_string($order['id'] ?? null) || ! is_string($order['status'] ?? null) || $order['status'] === '') {
            return null;
        }

        return $this->resultFromOrder($order);
    }

    /**
     * `POST /v1/orders/{id}/cancel` (sem corpo; `X-Idempotency-Key` estável por pagamento).
     * Segundo a documentação só `created` e `action_required` são canceláveis. Tratamento:
     *  - 2xx: cancelada;
     *  - erro `order_already_canceled` (409 observado na sandbox): já estava cancelada;
     *  - erro `cannot_cancel_order` (409 observado; a documentação o lista como 400): o estado
     *    mudou (ex.: pago no último instante). O chamador reconsulta e respeita o estado do MP;
     *  - rede/timeout, 5xx, 401/403, qualquer outro erro: indisponível (nada se sabe do estado).
     * Nunca lança e nunca devolve corpo/URL/token.
     */
    public function cancelPayment(Tenant $tenant, string $paymentId, string $idempotencyKey): PaymentCancellation
    {
        $accessToken = $this->getAccessToken($tenant);

        if (! $accessToken || ! $this->isLookupableId($paymentId)) {
            return PaymentCancellation::unavailable();
        }

        try {
            $response = $this->makeRequest(
                $accessToken,
                'POST',
                '/orders/'.$paymentId.'/cancel',
                [],
                ['X-Idempotency-Key' => substr($idempotencyKey, 0, 128)]
            );
        } catch (\Throwable) {
            return PaymentCancellation::unavailable();
        }

        if (! $response instanceof Response) {
            return PaymentCancellation::unavailable();
        }

        if ($response->successful()) {
            return PaymentCancellation::canceled();
        }

        $json = (array) $response->json();
        $codes = collect((array) ($json['errors'] ?? []))
            ->map(fn ($e) => is_array($e) && is_string($e['code'] ?? null) ? $e['code'] : null)
            ->filter()
            ->values();

        if ($codes->contains('order_already_canceled')) {
            return PaymentCancellation::alreadyCanceled();
        }

        if ($codes->contains('cannot_cancel_order')) {
            $status = data_get($json, 'data.status');

            return PaymentCancellation::notCancelable(is_string($status) ? $status : null, 'cannot_cancel_order');
        }

        return PaymentCancellation::unavailable($response->status(), $codes->first());
    }

    /**
     * Webhook de Orders: `type: order`, `action: order.<estado>` (`order.processed`,
     * `order.canceled`...), `data.id` = id da order. Outros tópicos (`payment`, chargebacks...)
     * e ids que não são de order (ids numéricos da API antiga) são ignorados com 200.
     */
    public function parseWebhook(array $payload, array $headers): WebhookEvent
    {
        $type = (string) ($payload['type'] ?? $payload['topic'] ?? '');
        $orderId = data_get($payload, 'data.id') ?? ($payload['id'] ?? null);

        if ($type !== '' && ! in_array($type, ['order', 'orders'], true)) {
            return WebhookEvent::ignored('Tipo de notificação não suportado: '.$type);
        }

        if (! is_string($orderId) || $orderId === '') {
            return WebhookEvent::invalid('Payload inválido: id da order ausente.');
        }

        if (! $this->isLookupableId($orderId)) {
            return WebhookEvent::ignored('Id não é de uma order do Mercado Pago (API de Orders).');
        }

        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? null;

        return WebhookEvent::payment($orderId, is_string($requestId) ? $requestId : null);
    }

    /**
     * O `id` assinado é o `data.id` da QUERY STRING (o PHP o entrega como `data_id`); o corpo
     * traz o mesmo valor em `data.id`. Se vierem os dois e divergirem, rejeita.
     */
    public function validateWebhook(Tenant $tenant, array $payload, array $headers): bool
    {
        $fromQuery = $payload['data_id'] ?? ($payload['data.id'] ?? null);
        $fromBody = data_get($payload, 'data.id') ?? ($payload['id'] ?? null);

        $query = is_string($fromQuery) ? $fromQuery : '';
        $body = is_string($fromBody) ? $fromBody : '';

        if ($query !== '' && $body !== '' && strcasecmp($query, $body) !== 0) {
            Log::warning('MercadoPago webhook: data.id da query diverge do corpo');

            return false;
        }

        return $this->validateWebhookSignature($query !== '' ? $query : $body, $headers, $this->getWebhookSecret($tenant));
    }

    /**
     * Manifesto assinado: `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` (HMAC-SHA256 com o
     * segredo da aplicação). Para ids alfanuméricos o MP assina o `data.id` em MINÚSCULAS (regra
     * da documentação de webhooks; o SDK oficial também o minúsculiza). Como a documentação de
     * Orders não mostra o manifesto e não foi possível receber um webhook real, aceita-se a forma
     * minúscula (documentada) e a literal: as duas exigem o segredo, então nada se enfraquece.
     */
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

        foreach (array_unique([strtolower($dataId), $dataId]) as $id) {
            $manifest = "id:{$id};request-id:{$xRequestId};ts:{$ts};";

            if (hash_equals(hash_hmac('sha256', $manifest, $secretKey), $v1)) {
                return true;
            }
        }

        Log::warning('MercadoPago webhook: signature mismatch');

        return false;
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
                // Sem dados = sem corpo (o cancelamento não tem corpo; `[]` seria enviado como `[]`).
                'POST' => $request->post($url, $data === [] ? null : $data),
                default => null,
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return null;
        }
    }
}

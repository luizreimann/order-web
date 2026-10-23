<?php

namespace Tests\Feature\Payments\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use App\Support\EncryptionHelper;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MercadoPagoOrders;

/**
 * Fixtures compartilhadas pelos testes de pagamento (E3.3–E3.5).
 *
 * O secret do webhook é SEMPRE configurado nos tenants: em `testing` a ausência de
 * secret é um bypass legítimo (local/testing), e os testes de assinatura precisam
 * exercitar o caminho de produção.
 */
trait InteractsWithMercadoPago
{
    protected function mpTenant(string $slug = 'mp-loja', ?string $secret = 'whsec-'.'test', bool $withSecret = true): Tenant
    {
        $mp = [
            'public_key' => 'TEST-public-key',
            'access_token' => EncryptionHelper::encryptIfNeeded('TEST-access-token-'.$slug),
        ];

        if ($withSecret) {
            $mp['webhook_secret'] = EncryptionHelper::encryptIfNeeded($secret);
        }

        return Tenant::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'settings' => [
                'payment' => [
                    'mercadopago' => $mp,
                    'methods' => [
                        ['id' => 'mp_pix', 'enabled' => true],
                        ['id' => 'mp_credit_card', 'enabled' => true],
                        ['id' => 'mp_debit_card', 'enabled' => true],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Pedido pendente com a tentativa de pagamento já confirmada no gateway.
     *
     * @return array{0: Order, 1: Payment}
     */
    protected function orderWithMpPayment(Tenant $tenant, string $paymentId, string $paymentStatus = 'pending'): array
    {
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 'mp_pix',
            'meta' => ['mercadopago' => ['payment_id' => $paymentId]],
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => $paymentId,
            'amount_cents' => $order->total_cents,
            'status' => $paymentStatus,
        ]);

        return [$order, $payment];
    }

    /**
     * @return array<string, string> headers de um webhook do MP assinado com `$secret`
     */
    protected function mpSignedHeaders(string $dataId, string $secret = 'whsec-test', ?string $requestId = null): array
    {
        $requestId ??= (string) Str::uuid();
        $ts = (string) time();
        // O MP assina o `data.id` alfanumérico em MINÚSCULAS (documentação de webhooks / SDK).
        $hash = hash_hmac('sha256', 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$ts};", $secret);

        return ['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => $requestId];
    }

    /**
     * Resposta da API do MP para `GET /v1/orders/{id}` (formato de Orders observado na sandbox).
     * `$status` é o vocabulário do sistema (`approved`, `pending`...), traduzido por
     * {@see MercadoPagoOrders::statuses()}.
     */
    protected function fakeMpPaymentLookup(string $paymentId, string $status, Order $order, array $overrides = []): void
    {
        // `Http::fake()` ACUMULA stubs e vence o primeiro que casar: trocar a fábrica garante
        // que a resposta nova substitui a anterior (ex.: reentrega com a API de volta).
        Http::swap(new HttpFactory);

        Http::fake([
            'api.mercadopago.com/v1/orders/'.$paymentId => Http::response(MercadoPagoOrders::body($paymentId, $status, $order, $overrides), 200),
        ]);
    }

    /**
     * `POST /v1/orders` (criação) simulado: responde com uma order cujo `external_reference` é o
     * que o servidor ENVIOU (o id do pedido, que só existe depois do `begin()`), no formato real
     * de Orders. `$httpStatus` 201 = order criada; 402 = cartão recusado (`errors[]` + `data`).
     * Também atende `GET /v1/orders/{id}` (consultas do próprio fluxo) com o mesmo estado.
     *
     * @param  array<string, mixed>  $overrides  mesclados sobre o corpo da order
     */
    protected function fakeMpOrderCreation(string $orderId, string $status = 'pending', array $overrides = [], int $httpStatus = 201, bool $pixWithQr = true): void
    {
        $build = function (\Illuminate\Http\Client\Request $request) use ($orderId, $status, $overrides, $httpStatus, $pixWithQr) {
            $stub = new Order(['total_cents' => (int) round(((float) $request['total_amount']) * 100)]);
            $stub->forceFill(['id' => $request['external_reference'] ?? null]);

            if ($httpStatus === 402) {
                return Http::response(MercadoPagoOrders::declinedBody($orderId, $stub), 402);
            }

            return Http::response(MercadoPagoOrders::body($orderId, $status, $stub, $overrides, $pixWithQr), $httpStatus);
        };

        Http::fake([
            'api.mercadopago.com/v1/orders' => $build,
            'api.mercadopago.com/v1/orders/'.$orderId => fn () => Http::response(MercadoPagoOrders::body($orderId, $status, null, $overrides, $pixWithQr), 200),
        ]);
    }

    /**
     * Webhook de Orders: `type=order`, `data.id` = id da order; o `data.id` também vai na QUERY
     * (`?data.id=`), que é o que o MP assina.
     */
    protected function postMpWebhook(Tenant $tenant, string $paymentId, array $headers, array $body = []): TestResponse
    {
        return $this->postJson(
            '/webhooks/mercadopago/'.$tenant->id.'?data.id='.$paymentId.'&type=order',
            array_merge(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => $paymentId]], $body),
            $headers
        );
    }

    /**
     * Corpo do `GET /{tenant}/checkout/status` de um pedido PENDENTE com o QR do Pix já conhecido:
     * o status e SÓ os três campos do QR (nada mais do corpo do gateway).
     *
     * @return array<string, mixed>
     */
    protected function pendingPollJson(): array
    {
        return ['status' => 'pending', 'pix' => [
            'qr_code' => MercadoPagoOrders::QR_CODE,
            'qr_code_base64' => MercadoPagoOrders::QR_BASE64,
            'ticket_url' => MercadoPagoOrders::TICKET_URL,
        ]];
    }

    /**
     * A resposta de um pedido PENDENTE: o status, SÓ os três campos do QR e, se o pagamento tem a
     * janela do Pix gravada, SÓ os dois instantes do contador (`expires_at`, `server_now`), nada mais.
     */
    protected function assertPendingPoll(TestResponse $response): void
    {
        $json = (array) $response->json();
        $timing = array_intersect_key($json, array_flip(['expires_at', 'server_now']));

        $this->assertEquals($this->pendingPollJson(), array_diff_key($json, $timing));
        $this->assertContains(count($timing), [0, 2], 'expires_at e server_now vão juntos ou nenhum');

        if ($timing !== []) {
            $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $timing['expires_at']);
            $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $timing['server_now']);
        }
    }

    protected function product(Tenant $tenant, int $priceCents = 2500, ?int $stock = null): Product
    {
        return Product::factory()->create([
            'tenant_id' => $tenant->id,
            'price_cents' => $priceCents,
            'track_inventory' => $stock !== null,
            'stock_quantity' => $stock,
            'name' => 'Bolo de cenoura',
        ]);
    }

    /**
     * Sessão com carrinho (e o nonce de checkout, que o storefront gera ao abrir a
     * página): o mesmo estado reaplicado simula o "duplo clique" — duas requisições
     * que leram a sessão antes de qualquer uma limpar o carrinho.
     *
     * @return array<string, mixed>
     */
    protected function checkoutSession(Tenant $tenant, Product $product, int $qty = 1, string $nonce = 'nonce-fixo'): array
    {
        return [
            CartService::sessionKey($tenant) => [['product_id' => $product->id, 'qty' => $qty]],
            'checkout.nonce.'.$tenant->id => $nonce,
        ];
    }

    /** Corpo de `POST /{tenant}/process_payment` (formato do Payment Brick). */
    protected function processPaymentBody(array $overrides = []): array
    {
        return array_merge([
            'mp_method' => 'mp_pix',
            'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            'formData' => ['token' => 'card-token', 'payment_method_id' => 'pix'],
        ], $overrides);
    }

    /**
     * Captura o que for logado a partir daqui (o log continua indo ao canal normal).
     *
     * @return \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function captureLogs(): \ArrayObject
    {
        /** @var \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> $logs */
        $logs = new \ArrayObject;

        Log::listen(function (MessageLogged $event) use ($logs) {
            $logs->append(['level' => $event->level, 'message' => (string) $event->message, 'context' => $event->context]);
        });

        return $logs;
    }

    /**
     * @param  \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>  $logs
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function logsNamed(\ArrayObject $logs, string $message): array
    {
        return array_values(array_filter($logs->getArrayCopy(), fn ($entry) => $entry['message'] === $message));
    }

    protected function orderStatusOf(Order $order): OrderStatus
    {
        return Order::withoutTenantScope()->findOrFail($order->id)->status;
    }
}

<?php

/**
 * Processo-filho dos testes de concorrência de pagamento (E3.3/E3.4). NÃO é um teste.
 *
 * Boota o Laravel num processo PHP separado (conexão PostgreSQL própria), espera a
 * "largada" (advisory lock segurado pelo pai) e executa UMA operação real. Imprime uma
 * linha JSON.
 *
 *   webhook <tenant_id> <order_id> <payment_id> <status> <secret> <advisory_key>
 *       POST /webhooks/mercadopago/{tenant}?data.id=<order> pelo kernel HTTP, assinado (tópico
 *       `order`), com a API de Orders do MP simulada (Http::fake) devolvendo `<status>`.
 *
 *   status <tenant_id> <order_id> <payment_id> <status> <advisory_key>
 *       GET /{tenant}/checkout/status (TenantPaymentController::status, que reconcilia com o
 *       MP) com a sessão do comprador e a API do MP simulada. Imprime o status devolvido e
 *       quantas consultas fez ao MP. O throttle (cache `array`) é por processo: cada filho
 *       consulta de verdade, então quem impede a dupla transição é o caminho idempotente.
 *
 *   reconcile <tenant_id> <order_id> <payment_id> <status> <advisory_key>
 *       PaymentReconciler::reconcileOrder() SEM throttle (todas as consultas chegam ao MP
 *       simulado e ao banco ao mesmo tempo). Imprime `transitioned`: só UM filho pode ser true.
 *
 *   expire <tenant_id> <order_id> <payment_id> <mp_state> <advisory_key>
 *       GET /{tenant}/checkout/status de um Pix com a JANELA VENCIDA (TenantPaymentController::status
 *       -> PaymentReconciler -> PixExpiryEnforcer). A API do MP é simulada com estado POR PROCESSO:
 *       `pending` = aguardando até o `POST .../cancel` (que a deixa `cancelled`); `approved` = já paga.
 *       Imprime o corpo devolvido e quantos `POST .../cancel` o filho fez. Sob concorrência, só UMA
 *       transição pode acontecer (locks pedido -> pagamento) e o estoque volta uma vez.
 *
 *   begin <tenant_id> <product_id> <qty> <checkout_key> <advisory_key>
 *       PaymentService::begin() (pedido + tentativa de pagamento numa transação).
 */

use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Gateways\PaymentGatewayManager;
use App\Http\Controllers\TenantPaymentController;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\PaymentReconciler;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MercadoPagoOrders;

$mode = $argv[1];

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$barrier = function (string $key): void {
    DB::select('select pg_advisory_lock(?)', [(int) $key]);
    DB::select('select pg_advisory_unlock(?)', [(int) $key]);
};

try {
    if ($mode === 'webhook') {
        [, , $tenantId, $orderId, $paymentId, $status, $secret, $key] = $argv;

        Http::fake([
            'api.mercadopago.com/v1/orders/'.$paymentId => Http::response(MercadoPagoOrders::body($paymentId, $status, null, ['external_reference' => $orderId]), 200),
        ]);

        $requestId = 'req-'.getmypid();
        $ts = (string) time();
        $hash = hash_hmac('sha256', 'id:'.strtolower($paymentId).";request-id:{$requestId};ts:{$ts};", $secret);
        $body = json_encode(['type' => 'order', 'action' => 'order.processed', 'data' => ['id' => $paymentId]]);

        $request = Request::create('/webhooks/mercadopago/'.$tenantId.'?data.id='.$paymentId.'&type=order', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => "ts={$ts},v1={$hash}",
            'HTTP_X_REQUEST_ID' => $requestId,
        ], $body);

        // Aquece rotas/autoload/conexão ANTES da largada: a corrida é só o webhook.
        DB::select('select 1');
        $kernel = $app->make(HttpKernel::class);

        $barrier($key);

        $response = $kernel->handle($request);

        echo json_encode(['result' => 'http', 'status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)]), "\n";
    } elseif ($mode === 'status') {
        [, , $tenantId, $orderId, $paymentId, $status, $key] = $argv;

        Http::fake([
            'api.mercadopago.com/v1/orders/'.$paymentId => Http::response(MercadoPagoOrders::body($paymentId, $status, null, ['external_reference' => $orderId]), 200),
        ]);

        $tenant = Tenant::query()->findOrFail($tenantId);
        $app->make(TenantContext::class)->set($tenant);

        $request = Request::create('/'.$tenant->slug.'/checkout/status', 'GET');
        $session = $app->make('session')->driver();
        $session->put(TenantPaymentController::orderKey($tenant), $orderId);
        $request->setLaravelSession($session);

        $controller = $app->make(TenantPaymentController::class);

        DB::select('select 1');
        $barrier($key);

        $response = $controller->status($request, $tenant);

        $lookups = Http::recorded(fn ($r) => $r->method() === 'GET')->count();

        echo json_encode(['result' => 'status', 'http' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true), 'lookups' => $lookups]), "\n";
    } elseif ($mode === 'expire') {
        [, , $tenantId, $orderId, $paymentId, $mpState, $key] = $argv;

        $canceled = false;
        Http::fake([
            'api.mercadopago.com/v1/orders/'.$paymentId.'/cancel' => function () use (&$canceled, $paymentId, $orderId) {
                $canceled = true;

                return Http::response(MercadoPagoOrders::body($paymentId, 'cancelled', null, ['external_reference' => $orderId]), 200);
            },
            'api.mercadopago.com/v1/orders/'.$paymentId => function () use (&$canceled, $paymentId, $orderId, $mpState) {
                $state = $canceled ? 'cancelled' : $mpState;

                return Http::response(MercadoPagoOrders::body($paymentId, $state, null, ['external_reference' => $orderId]), 200);
            },
        ]);

        $tenant = Tenant::query()->findOrFail($tenantId);
        $app->make(TenantContext::class)->set($tenant);

        $request = Request::create('/'.$tenant->slug.'/checkout/status', 'GET');
        $session = $app->make('session')->driver();
        $session->put(TenantPaymentController::orderKey($tenant), $orderId);
        $request->setLaravelSession($session);

        $controller = $app->make(TenantPaymentController::class);

        DB::select('select 1');
        $barrier($key);

        $response = $controller->status($request, $tenant);

        $cancels = Http::recorded(fn ($r) => $r->method() === 'POST')->count();

        echo json_encode(['result' => 'expire', 'http' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true), 'cancels' => $cancels]), "\n";
    } elseif ($mode === 'reconcile') {
        [, , $tenantId, $orderId, $paymentId, $status, $key] = $argv;

        Http::fake([
            'api.mercadopago.com/v1/orders/'.$paymentId => Http::response(MercadoPagoOrders::body($paymentId, $status, null, ['external_reference' => $orderId]), 200),
        ]);

        $tenant = Tenant::query()->findOrFail($tenantId);
        $reconciler = $app->make(PaymentReconciler::class);
        $order = $app->make(TenantContext::class)->runFor($tenant, fn () => Order::query()->findOrFail($orderId));

        DB::select('select 1');
        $barrier($key);

        $result = $app->make(TenantContext::class)->runFor($tenant, fn () => $reconciler->reconcileOrder($tenant, $order, throttle: false));

        echo json_encode(['result' => 'reconcile', 'consulted' => $result !== null, 'transitioned' => (bool) $result?->transitioned, 'error' => $result?->errorMessage]), "\n";
    } elseif ($mode === 'begin') {
        [, , $tenantId, $productId, $qty, $checkoutKey, $key] = $argv;

        $tenant = Tenant::query()->findOrFail($tenantId);
        $gateway = $app->make(PaymentGatewayManager::class)->forProvider('mercadopago');
        $service = $app->make(PaymentService::class);

        $barrier($key);

        $attempt = $service->begin(
            $tenant,
            $gateway,
            PaymentMethod::MP_PIX,
            ['name' => 'Cliente', 'email' => 'c@example.com', 'phone' => '11990000000'],
            [['product_id' => $productId, 'qty' => (int) $qty]],
            $checkoutKey,
        );

        echo json_encode(['result' => $attempt->duplicate ? 'duplicate' : 'fresh', 'order_id' => $attempt->order->id, 'payment_id' => $attempt->payment->id]), "\n";
    }
} catch (InsufficientStockException $e) {
    echo json_encode(['result' => 'insufficient']), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}

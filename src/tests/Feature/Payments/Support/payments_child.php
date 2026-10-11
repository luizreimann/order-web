<?php

/**
 * Processo-filho dos testes de concorrência de pagamento (E3.3/E3.4). NÃO é um teste.
 *
 * Boota o Laravel num processo PHP separado (conexão PostgreSQL própria), espera a
 * "largada" (advisory lock segurado pelo pai) e executa UMA operação real. Imprime uma
 * linha JSON.
 *
 *   webhook <tenant_id> <order_id> <payment_id> <status> <secret> <advisory_key>
 *       POST /webhooks/mercadopago/{tenant} pelo kernel HTTP, assinado, com a API do MP
 *       simulada (Http::fake) devolvendo `<status>`.
 *
 *   begin <tenant_id> <product_id> <qty> <checkout_key> <advisory_key>
 *       PaymentService::begin() (pedido + tentativa de pagamento numa transação).
 */

use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Gateways\PaymentGatewayManager;
use App\Models\Tenant;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
            'api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => (int) $paymentId,
                'status' => $status,
                'status_detail' => 'detail_'.$status,
                'metadata' => ['tenant_id' => $tenantId, 'order_id' => $orderId],
            ], 200),
        ]);

        $requestId = 'req-'.getmypid();
        $ts = (string) time();
        $hash = hash_hmac('sha256', "id:{$paymentId};request-id:{$requestId};ts:{$ts};", $secret);
        $body = json_encode(['type' => 'payment', 'data' => ['id' => $paymentId]]);

        $request = Request::create('/webhooks/mercadopago/'.$tenantId, 'POST', [], [], [], [
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

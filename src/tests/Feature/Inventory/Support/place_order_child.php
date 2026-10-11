<?php

/**
 * Processo-filho do teste de concorrência (E2.7). NÃO é um teste.
 *
 * Boota o Laravel num processo PHP separado (conexão PostgreSQL própria), espera a
 * "largada" (advisory lock mantido pelo processo pai) e tenta fechar um pedido via
 * OrderService::createFromCart. Imprime UMA linha JSON com o resultado.
 *
 * Uso: php place_order_child.php <tenant_id> <product_id> <qty> <advisory_key>
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $tenantId, $productId, $qty, $advisoryKey] = $argv;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    // Aquece a conexão e o autoload ANTES da largada, para a corrida ser só o pedido.
    $tenant = Tenant::query()->findOrFail($tenantId);
    $service = new OrderService;

    // Barreira: o pai segura o lock; todos os filhos ficam parados aqui e são
    // liberados no mesmo instante quando o pai solta.
    DB::select('select pg_advisory_lock(?)', [(int) $advisoryKey]);
    DB::select('select pg_advisory_unlock(?)', [(int) $advisoryKey]);

    $order = $service->createFromCart(
        $tenant,
        ['name' => 'Cliente '.getmypid(), 'email' => 'c'.getmypid().'@example.com', 'phone' => '1190000'.getmypid()],
        [['product_id' => $productId, 'qty' => (int) $qty]],
        'cash_on_delivery'
    );

    echo json_encode(['result' => 'ok', 'order_id' => $order->id, 'number' => $order->number]), "\n";
} catch (InsufficientStockException $e) {
    echo json_encode(['result' => 'insufficient', 'available' => $e->available]), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}

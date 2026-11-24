<?php

/**
 * Processo-filho da corrida da vaga. NÃO é um teste.
 *
 * Boota o Laravel num processo PHP separado (conexão PostgreSQL própria), espera a
 * "largada" (advisory lock de um argumento, mantido pelo pai) e tenta fechar um
 * pedido com agendamento. A chave desta espera não é `order-schedule`: esse nome
 * é o lock da vaga, dentro da transação. Imprime UMA linha JSON com o resultado.
 *
 * Uso: php place_scheduled_order_child.php <tenant_id> <product_id> <date> <advisory_key>
 */

use App\Exceptions\SchedulingException;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $tenantId, $productId, $date, $advisoryKey] = $argv;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    // Aquece a conexão e o autoload ANTES da largada, para a corrida ser só o pedido.
    $tenant = Tenant::query()->findOrFail($tenantId);
    $service = new OrderService;

    // Barreira de um argumento. O pai segura o lock; os filhos saem juntos.
    DB::select('select pg_advisory_lock(?)', [(int) $advisoryKey]);
    DB::select('select pg_advisory_unlock(?)', [(int) $advisoryKey]);

    $suffix = str_pad((string) (getmypid() % 100000000), 8, '0', STR_PAD_LEFT);

    $order = $service->createFromCart(
        $tenant,
        ['name' => 'Cliente '.getmypid(), 'email' => 'c'.getmypid().'@example.com', 'phone' => '119'.$suffix],
        [['product_id' => $productId, 'qty' => 1]],
        'cash_on_delivery',
        [],
        ['type' => 'pickup'],
        [
            'date' => $date,
            'option' => ['kind' => 'time', 'start' => '10:00', 'end' => null],
        ],
    );

    echo json_encode([
        'result' => 'ok',
        'order_id' => $order->id,
        'scheduled_on' => $order->scheduled_on?->toDateString(),
    ]), "\n";
} catch (SchedulingException $e) {
    echo json_encode([
        'result' => 'refused',
        'field' => array_key_first($e->errors),
        'message' => $e->getMessage(),
    ]), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}

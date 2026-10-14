<?php

/**
 * Processo-filho do teste de concorrência do limite de vitrines (VAL-004). NÃO é um teste.
 *
 * Boota o Laravel num processo PHP separado (conexão PostgreSQL própria), espera a
 * "largada" (advisory lock mantido pelo pai) e chama Showcase::createWithinLimit.
 * Imprime UMA linha JSON com o resultado.
 *
 * Uso: php create_showcase_child.php <tenant_id> <advisory_key>
 */

use App\Exceptions\ShowcaseLimitReachedException;
use App\Models\Showcase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $tenantId, $advisoryKey] = $argv;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    // Aquece conexão e autoload antes da largada: a corrida é só a criação.
    DB::select('select 1');

    DB::select('select pg_advisory_lock(?)', [(int) $advisoryKey]);
    DB::select('select pg_advisory_unlock(?)', [(int) $advisoryKey]);

    $showcase = Showcase::createWithinLimit($tenantId, [
        'name' => 'Vitrine '.getmypid(),
        'type' => Showcase::TYPE_ALL,
        'display_mode' => 'list',
        'max_items' => 8,
        'is_active' => true,
    ]);

    echo json_encode(['result' => 'ok', 'id' => $showcase->id, 'sort_order' => $showcase->sort_order]), "\n";
} catch (ShowcaseLimitReachedException $e) {
    echo json_encode(['result' => 'limit']), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}

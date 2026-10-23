<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Janela de pagamento do Pix (5 min): o servidor expira o Pix vencido mesmo sem navegador aberto.
// Só varre Pix vencidos (`--pix-only`); a rotina consulta o MP antes de qualquer cancelamento e
// nunca cancela pedido pago. Exige o agendador do Laravel em produção (cron `schedule:run`).
Schedule::command('payments:reconcile --pix-only')
    ->everyMinute()
    ->withoutOverlapping(10); // o mutex expira em 10 min (padrão 24 h) se um processo morrer

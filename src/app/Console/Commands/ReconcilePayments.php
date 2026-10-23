<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Tenant;
use App\Services\PaymentReconciler;
use App\Services\PixExpiryEnforcer;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Varre pagamentos em aberto (com id no gateway) e os reconcilia pelo mesmo caminho do webhook:
 *
 *  1. Pix cuja JANELA DE PAGAMENTO venceu ({@see \App\Support\PixWindow}): a mesma rotina do
 *     polling do checkout ({@see PixExpiryEnforcer}) consulta, respeita um pagamento já feito e,
 *     se ainda aguardando, cancela no gateway e então o pedido. Independe do navegador: é o que
 *     libera o estoque quando o cliente fechou a aba. Vale qualquer idade (a janela é de minutos);
 *  2. demais pagamentos em aberto criados há pelo menos `--minutes` (os recentes estão no
 *     polling do checkout), a menos que `--pix-only`.
 *
 * Agendado a cada minuto em `routes/console.php` (`--pix-only`); em produção o agendador do
 * Laravel precisa rodar (`* * * * * php artisan schedule:run`). Sem agendador, roda sob demanda.
 *
 * Roda por tenant (contexto de tenant + `forTenant`), sem consulta cross-tenant.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
        {--minutes=10 : Só pagamentos criados há pelo menos N minutos (os recentes estão no polling do checkout)}
        {--limit=200 : Máximo de pagamentos consultados por execução}
        {--pix-only : Só os Pix com a janela de pagamento vencida}';

    protected $description = 'Expira os Pix vencidos e consulta no gateway os pagamentos ainda em aberto (idempotente, igual ao webhook)';

    public function handle(PaymentReconciler $reconciler, PixExpiryEnforcer $expiry, TenantContext $context): int
    {
        $minutes = max(0, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $pixOnly = (bool) $this->option('pix-only');
        $cutoff = now()->subMinutes($minutes);

        $checked = 0;
        $changed = 0;

        Tenant::query()->where('is_active', true)->orderBy('id')->each(function (Tenant $tenant) use (&$checked, &$changed, $reconciler, $expiry, $context, $cutoff, $limit, $pixOnly) {
            if ($limit - $checked <= 0) {
                return false;
            }

            $context->runFor($tenant, function () use ($tenant, &$checked, &$changed, $reconciler, $expiry, $cutoff, $limit, $pixOnly) {
                // 1) Pix vencidos. O prazo está em `meta` (JSON): filtra no PHP, sobre os poucos
                //    pagamentos em aberto que têm janela gravada.
                $due = Payment::query()
                    ->forTenant($tenant->id)
                    ->whereNotNull('gateway_payment_id')
                    ->whereIn('status', PaymentReconciler::openStatuses())
                    ->whereNotNull('meta->pix_expires_at')
                    ->orderBy('created_at')
                    ->get()
                    ->filter(fn (Payment $p) => $expiry->isDue($p))
                    ->take($limit - $checked);

                $seen = [];

                foreach ($due as $payment) {
                    $seen[] = $payment->id;
                    $result = $reconciler->reconcile($tenant, $payment, throttle: false);
                    $checked++;

                    if ($result?->transitioned) {
                        $changed++;
                    }
                }

                if ($pixOnly || $limit - $checked <= 0) {
                    return;
                }

                // 2) Os demais em aberto, mais velhos que o corte.
                $payments = Payment::query()
                    ->forTenant($tenant->id)
                    ->whereNotNull('gateway_payment_id')
                    ->whereIn('status', PaymentReconciler::openStatuses())
                    ->where('created_at', '<=', $cutoff)
                    ->whereNotIn('id', $seen)
                    ->orderBy('created_at')
                    ->limit($limit - $checked)
                    ->get();

                foreach ($payments as $payment) {
                    $result = $reconciler->reconcile($tenant, $payment, throttle: false);
                    $checked++;

                    if ($result?->transitioned) {
                        $changed++;
                    }
                }
            });

            return true;
        });

        $this->info("Pagamentos consultados: {$checked}; atualizados: {$changed}.");

        return self::SUCCESS;
    }
}

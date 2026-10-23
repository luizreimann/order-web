<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\PixWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * `php artisan payments:reconcile`: varredura (sem agendar) dos pagamentos em aberto, pelo
 * mesmo caminho do webhook.
 */
class ReconcilePaymentsCommandTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
    }

    /** @return array{0: Order, 1: Payment} */
    private function open(string $paymentId, string $status = 'pending', int $ageMinutes = 30, ?Tenant $tenant = null): array
    {
        [$order, $payment] = $this->orderWithMpPayment($tenant ?? $this->tenant, $paymentId, $status);
        Payment::withoutTenantScope()->whereKey($payment->id)->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return [$order, $payment];
    }

    /** @param  array<int|string, array{0: string, 1: Order}>  $byId  id => [status, order] */
    private function mpSays(array $byId): void
    {
        Http::swap(new HttpFactory);
        $stubs = [];
        foreach ($byId as $id => [$status, $order]) {
            $stubs['api.mercadopago.com/v1/orders/'.$id] = Http::response(MercadoPagoOrders::body($id, $status, $order), 200);
        }
        Http::fake($stubs);
    }

    public function test_it_reconciles_open_payments_older_than_the_threshold(): void
    {
        [$paid] = $this->open('ORDTEST900001');
        [$expired] = $this->open('ORDTEST900002');
        $this->mpSays(['ORDTEST900001' => ['approved', $paid], 'ORDTEST900002' => ['cancelled', $expired]]);

        $this->artisan('payments:reconcile', ['--minutes' => 10])
            ->expectsOutputToContain('consultados: 2; atualizados: 2')
            ->assertSuccessful();

        $this->assertSame(PaymentStatus::PAID, $paid->fresh()->payment_status);
        $this->assertSame(OrderStatus::CANCELLED, $expired->fresh()->status);
    }

    public function test_it_skips_recent_final_and_unidentified_payments(): void
    {
        [$recent] = $this->open('ORDTEST900003', ageMinutes: 2);
        [$approved] = $this->open('ORDTEST900004', 'approved');
        [$rejected] = $this->open('ORDTEST900005', 'rejected');
        [, $noId] = $this->open('ORDTEST900006', 'created');
        Payment::withoutTenantScope()->whereKey($noId->id)->update(['gateway_payment_id' => null]);
        $this->mpSays(['ORDTEST900003' => ['approved', $recent]]);

        $this->artisan('payments:reconcile', ['--minutes' => 10])->expectsOutputToContain('consultados: 0')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::UNPAID, $recent->fresh()->payment_status);
    }

    public function test_minutes_zero_includes_recent_payments(): void
    {
        [$recent] = $this->open('ORDTEST900003', ageMinutes: 0);
        $this->mpSays(['ORDTEST900003' => ['approved', $recent]]);

        $this->artisan('payments:reconcile', ['--minutes' => 0])->expectsOutputToContain('consultados: 1; atualizados: 1')->assertSuccessful();

        $this->assertSame(PaymentStatus::PAID, $recent->fresh()->payment_status);
    }

    public function test_it_is_idempotent_across_runs(): void
    {
        [$order] = $this->open('ORDTEST900001');
        $this->mpSays(['ORDTEST900001' => ['approved', $order]]);

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->artisan('payments:reconcile')->expectsOutputToContain('consultados: 0')->assertSuccessful();

        $this->assertSame(1, \DB::table('payment_webhook_events')->where('gateway_payment_id', 'ORDTEST900001')->count());
    }

    public function test_it_works_across_tenants_with_each_tenants_own_credentials(): void
    {
        $other = $this->mpTenant('outra-loja');
        [$a] = $this->open('ORDTEST900001');
        [$b] = $this->open('ORDTEST900007', tenant: $other);
        $this->mpSays(['ORDTEST900001' => ['approved', $a], 'ORDTEST900007' => ['approved', $b]]);

        $this->artisan('payments:reconcile')->expectsOutputToContain('atualizados: 2')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ORDTEST900001') && $r->hasHeader('Authorization', 'Bearer TEST-access-token-mp-loja'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ORDTEST900007') && $r->hasHeader('Authorization', 'Bearer TEST-access-token-outra-loja'));
    }

    public function test_the_limit_caps_the_number_of_lookups(): void
    {
        $orders = [];
        foreach (['ORDTEST900011', 'ORDTEST900012', 'ORDTEST900013'] as $id) {
            [$orders[$id]] = $this->open($id);
        }
        $this->mpSays(array_map(fn (Order $o) => ['approved', $o], $orders));

        $this->artisan('payments:reconcile', ['--limit' => 2])->expectsOutputToContain('consultados: 2')->assertSuccessful();
    }

    public function test_a_gateway_outage_does_not_fail_the_command_and_changes_nothing(): void
    {
        [$order] = $this->open('ORDTEST900001');
        Http::fake(['api.mercadopago.com/*' => Http::response('', 503)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);
    }

    public function test_a_payment_approved_for_a_cancelled_order_raises_the_refund_incident_and_does_not_reopen_it(): void
    {
        [$order] = $this->open('ORDTEST900001');
        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();
        $this->mpSays(['ORDTEST900001' => ['approved', $order]]);
        $logs = $this->captureLogs();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $entries = $this->logsNamed($logs, 'payment.incident.paid_on_cancelled_order');
        $this->assertCount(1, $entries);
        $this->assertSame('critical', $entries[0]['level']);
    }

    public function test_inactive_tenants_are_skipped(): void
    {
        [$order] = $this->open('ORDTEST900001');
        Tenant::query()->whereKey($this->tenant->id)->update(['is_active' => false]);
        $this->mpSays(['ORDTEST900001' => ['approved', $order]]);

        $this->artisan('payments:reconcile')->expectsOutputToContain('consultados: 0')->assertSuccessful();
    }

    public function test_only_the_pix_expiry_sweep_is_scheduled_every_minute_without_overlap(): void
    {
        // O agendamento (decisão do §16) é SÓ a varredura de Pix vencidos, a cada minuto e sem
        // sobreposição; a varredura geral (todos os pagamentos em aberto) continua sob demanda.
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'payments:reconcile'));

        $this->assertCount(1, $scheduled);

        $event = $scheduled->first();
        $this->assertStringContainsString('payments:reconcile --pix-only', (string) $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ------------------------------------------------------------------ Pix vencido, sem navegador

    /**
     * Pix aberto com a janela gravada: `$expiredSecondsAgo` > 0 = o prazo já passou há N segundos.
     *
     * @return array{0: Order, 1: Payment}
     */
    private function pix(string $paymentId, int $expiredSecondsAgo, int $ageMinutes = 6, ?string $status = 'pending'): array
    {
        [$order, $payment] = $this->open($paymentId, $status, $ageMinutes);
        Payment::withoutTenantScope()->whereKey($payment->id)->update([
            'meta' => ['pix_expires_at' => PixWindow::format(now()->subSeconds($expiredSecondsAgo))],
        ]);

        return [$order, $payment->fresh()];
    }

    /** `GET` responde a sequência de estados; `POST .../cancel` responde `$cancel`. */
    private function mpExpiry(string $id, Order $order, array $states, mixed $cancel = null): void
    {
        Http::swap(new HttpFactory);
        $sequence = Http::sequence();
        foreach ($states as $state) {
            $sequence->push(MercadoPagoOrders::body($id, $state, $order), 200);
        }
        $sequence->whenEmpty(Http::response(MercadoPagoOrders::body($id, end($states), $order), 200));

        Http::fake([
            'api.mercadopago.com/v1/orders/'.$id.'/cancel' => $cancel ?? Http::response(MercadoPagoOrders::body($id, 'cancelled', $order), 200),
            'api.mercadopago.com/v1/orders/'.$id => $sequence,
        ]);
    }

    private function cancelPosts(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cancel'))->count();
    }

    public function test_it_expires_a_pix_whose_window_closed_without_any_browser_even_if_it_is_only_minutes_old(): void
    {
        // Criado há 6 min (a varredura geral só olha pagamentos de 10+ min), janela fechada há 60 s.
        [$order, $payment] = $this->pix('ORDTEST900021', expiredSecondsAgo: 60);
        $this->mpExpiry('ORDTEST900021', $order, ['pending', 'cancelled']);

        $this->artisan('payments:reconcile', ['--pix-only' => true])
            ->expectsOutputToContain('consultados: 1; atualizados: 1')
            ->assertSuccessful();

        $this->assertSame(1, $this->cancelPosts());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/orders/ORDTEST900021/cancel')
            && $r->hasHeader('X-Idempotency-Key', 'pix-expiry:'.$payment->id));
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame('cancelled', $payment->fresh()->status);

        // Segunda rodada: nada mais a fazer.
        $this->artisan('payments:reconcile', ['--pix-only' => true])->expectsOutputToContain('consultados: 0')->assertSuccessful();
        $this->assertSame(1, $this->cancelPosts());
    }

    public function test_the_default_run_also_expires_due_pix_and_does_not_query_the_same_payment_twice(): void
    {
        [$order] = $this->pix('ORDTEST900022', expiredSecondsAgo: 60, ageMinutes: 30);
        $this->mpExpiry('ORDTEST900022', $order, ['pending', 'cancelled']);

        $this->artisan('payments:reconcile')->expectsOutputToContain('consultados: 1; atualizados: 1')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(1, $this->cancelPosts());
    }

    public function test_a_due_pix_that_was_paid_meanwhile_is_paid_never_cancelled(): void
    {
        [$order] = $this->pix('ORDTEST900023', expiredSecondsAgo: 120);
        $this->mpExpiry('ORDTEST900023', $order, ['approved']);

        $this->artisan('payments:reconcile', ['--pix-only' => true])->expectsOutputToContain('atualizados: 1')->assertSuccessful();

        $this->assertSame(0, $this->cancelPosts());
        $this->assertSame(PaymentStatus::PAID, $order->fresh()->payment_status);
        $this->assertNotSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_cancel_failure_leaves_it_for_the_next_run_which_finishes_it(): void
    {
        [$order] = $this->pix('ORDTEST900024', expiredSecondsAgo: 120);
        $this->mpExpiry('ORDTEST900024', $order, ['pending'], Http::response('', 503));
        $logs = $this->captureLogs();

        $this->artisan('payments:reconcile', ['--pix-only' => true])->assertSuccessful();

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertCount(1, $this->logsNamed($logs, 'payment.pix_expiry.cancel_failed'));

        $this->mpExpiry('ORDTEST900024', $order, ['pending', 'cancelled']);
        $this->artisan('payments:reconcile', ['--pix-only' => true])->expectsOutputToContain('atualizados: 1')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_it_does_not_touch_a_pix_inside_its_window_or_inside_the_grace(): void
    {
        $this->travelTo('2026-10-23 12:00:00'); // relógio parado: a fronteira da tolerância é exata
        [$inside] = $this->pix('ORDTEST900025', expiredSecondsAgo: -60);
        [$grace] = $this->pix('ORDTEST900026', expiredSecondsAgo: PixWindow::GRACE_SECONDS);
        $this->mpExpiry('ORDTEST900025', $inside, ['pending']);

        $this->artisan('payments:reconcile', ['--pix-only' => true])->expectsOutputToContain('consultados: 0')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING, $inside->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, $grace->fresh()->status);
    }

    public function test_it_does_not_touch_card_payments_or_pix_without_a_stored_window_with_pix_only(): void
    {
        // Cartão em análise, antigo; Pix de antes da janela, antigo; nenhum tem `pix_expires_at`.
        [$card] = $this->open('ORDTEST900027', 'in_process', ageMinutes: 60);
        [$legacyPix] = $this->open('ORDTEST900028', 'pending', ageMinutes: 60);
        $this->mpSays(['ORDTEST900027' => ['cancelled', $card], 'ORDTEST900028' => ['cancelled', $legacyPix]]);

        $this->artisan('payments:reconcile', ['--pix-only' => true])->expectsOutputToContain('consultados: 0')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING, $card->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, $legacyPix->fresh()->status);
    }

    public function test_without_pix_only_old_payments_without_a_window_are_still_reconciled_but_never_cancelled_by_the_window(): void
    {
        [$legacyPix] = $this->open('ORDTEST900029', 'pending', ageMinutes: 60);
        $this->mpSays(['ORDTEST900029' => ['pending', $legacyPix]]);

        $this->artisan('payments:reconcile')->expectsOutputToContain('consultados: 1')->assertSuccessful();

        $this->assertSame(0, $this->cancelPosts());
        $this->assertSame(OrderStatus::PENDING, $legacyPix->fresh()->status);
    }

    public function test_old_numeric_ids_from_the_previous_api_are_never_queried_nor_cancelled(): void
    {
        [$order] = $this->pix('1234567890', expiredSecondsAgo: 600, ageMinutes: 60);
        Http::fake();

        $this->artisan('payments:reconcile', ['--pix-only' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }
}

<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E3.3/E3.4 — o banco é a última barreira da idempotência: estes UNIQUEs são o que
 * impede duas transições / duas tentativas / dois pagamentos com o mesmo id no gateway.
 */
class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function payment(array $attrs = []): Payment
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);

        return Payment::create(array_merge([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'gateway' => 'mercadopago',
            'amount_cents' => 100, 'status' => 'created',
        ], $attrs));
    }

    public function test_gateway_payment_id_is_unique_per_gateway_but_null_does_not_collide(): void
    {
        $this->payment(['gateway_payment_id' => '1']);
        $this->payment(['gateway_payment_id' => null]);
        $this->payment(['gateway_payment_id' => null]);
        $this->payment(['gateway' => 'fake', 'gateway_payment_id' => '1']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->payment(['gateway_payment_id' => '1']);
    }

    public function test_idempotency_key_is_unique_per_gateway(): void
    {
        $this->payment(['idempotency_key' => 'k']);
        $this->payment(['gateway' => 'fake', 'idempotency_key' => 'k']);
        $this->payment(['idempotency_key' => null]);
        $this->payment(['idempotency_key' => null]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->payment(['idempotency_key' => 'k']);
    }

    private function event(Payment $payment, string $status, ?string $requestId = null): void
    {
        DB::table('payment_webhook_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id,
            'order_id' => $payment->order_id, 'gateway' => 'mercadopago', 'gateway_payment_id' => '77',
            'status' => $status, 'request_id' => $requestId, 'processed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_transition_can_be_recorded_only_once_per_payment_and_status(): void
    {
        $payment = $this->payment(['gateway_payment_id' => '77']);

        $this->event($payment, 'pending');
        $this->event($payment, 'approved', 'req-1');
        $this->event($payment, 'refunded', 'req-1'); // request_id não é único: só indexado

        $this->expectException(UniqueConstraintViolationException::class);
        $this->event($payment, 'approved');
    }

    public function test_events_are_financial_history_and_block_deleting_their_payment_order_or_tenant(): void
    {
        $payment = $this->payment(['gateway_payment_id' => '77']);
        $this->event($payment, 'approved');

        foreach ([['payments', $payment->id, 'id'], ['orders', $payment->order_id, 'id'], ['tenants', $payment->tenant_id, 'id']] as [$table, $id, $column]) {
            try {
                // Savepoint: no PostgreSQL um erro aborta a transação inteira do teste.
                DB::transaction(fn () => DB::table($table)->where($column, $id)->delete());
                $this->fail("{$table} foi apagado apesar de ter eventos de webhook");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('23503', $e->getCode(), $table);
            }
        }
    }
}

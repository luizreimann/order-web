<?php

namespace Tests\Feature\Database;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E2.6 — status inválido é rejeitado pelo cast (camada de aplicação) E pelo
 * CHECK do banco (camada de dados).
 */
class StatusConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_casts_status_and_payment_status_to_enums(): void
    {
        $order = Order::factory()->create();

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(PaymentStatus::UNPAID, $order->fresh()->payment_status);

        $order->status = OrderStatus::DONE;
        $order->payment_status = PaymentStatus::PAID;
        $order->save();

        $this->assertSame('done', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('paid', DB::table('orders')->where('id', $order->id)->value('payment_status'));
    }

    public function test_cast_rejects_invalid_order_status(): void
    {
        $this->expectException(\ValueError::class);

        new Order(['status' => 'enviado']);
    }

    public function test_cast_rejects_invalid_payment_status(): void
    {
        $this->expectException(\ValueError::class);

        new Order(['payment_status' => 'parcial']);
    }

    public function test_database_rejects_invalid_order_status(): void
    {
        $tenant = Tenant::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $tenant->id]);

        try {
            DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update(['status' => 'enviado']));
            $this->fail('CHECK de orders.status deveria rejeitar o valor.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]); // check_violation
            $this->assertStringContainsString('orders_status_valid', $e->getMessage());
        }
    }

    public function test_database_rejects_invalid_payment_status(): void
    {
        $order = Order::factory()->create();

        try {
            DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'parcial']));
            $this->fail('CHECK de orders.payment_status deveria rejeitar o valor.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('orders_payment_status_valid', $e->getMessage());
        }
    }

    public function test_database_accepts_every_enum_value(): void
    {
        $order = Order::factory()->create();

        foreach (OrderStatus::cases() as $status) {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);
            $this->assertSame($status->value, DB::table('orders')->where('id', $order->id)->value('status'));
        }
        foreach (PaymentStatus::cases() as $status) {
            DB::table('orders')->where('id', $order->id)->update(['payment_status' => $status->value]);
            $this->assertSame($status->value, DB::table('orders')->where('id', $order->id)->value('payment_status'));
        }
    }

    /**
     * O CHECK é literal na migration; este teste garante que ele não diverge dos cases.
     */
    public function test_check_constraints_list_exactly_the_enum_values(): void
    {
        $def = fn (string $name) => (string) DB::selectOne(
            'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?',
            [$name]
        )?->def;

        foreach ([
            'orders_status_valid' => OrderStatus::values(),
            'orders_payment_status_valid' => PaymentStatus::values(),
        ] as $constraint => $values) {
            preg_match_all("/'([a-z_]+)'::character varying/", $def($constraint), $m);

            $this->assertEqualsCanonicalizing($values, $m[1], $constraint);
        }
    }

    /**
     * `payments.status` guarda o status BRUTO do gateway (conjunto aberto, definido pelo
     * Mercado Pago). Sem cast e sem CHECK de propósito: um valor novo do MP não pode
     * derrubar o registro do pagamento nem o webhook.
     */
    public function test_payments_status_keeps_raw_gateway_value_without_check(): void
    {
        $order = Order::factory()->create();

        $payment = Payment::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => (string) Str::uuid(),
            'amount_cents' => 100,
            'status' => 'status_novo_do_mp',
        ]);

        $this->assertSame('status_novo_do_mp', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->gatewayStatus());

        $payment->update(['status' => 'in_process']);
        $this->assertTrue($payment->fresh()->isPending());
    }
}

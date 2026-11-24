<?php

namespace Tests\Feature\Scheduling;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `orders.scheduled_on` é o dia de calendário. A ida e a volta não puxam
 * o dia para trás: a leitura não usa `timezone()`.
 */
class SchedulingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_on_round_trips_the_calendar_day(): void
    {
        $order = Order::factory()->create([
            'scheduled_on' => '2026-11-12',
        ]);

        $stored = DB::table('orders')->where('id', $order->id)->value('scheduled_on');
        $fresh = $order->fresh();

        $index = $this->calendarDayIndex();

        $this->assertSame('date', $this->columnType('orders', 'scheduled_on'));
        $this->assertNotNull($index);
        $this->assertStringContainsString('(tenant_id, scheduled_on)', $index->indexdef);
        $this->assertSame('2026-11-12', $stored);
        $this->assertSame('2026-11-12', $fresh->scheduled_on->toDateString());
        $this->assertSame('2026-11-12', $fresh->scheduled_on->format('Y-m-d'));
        $this->assertSame('12/11/2026', $fresh->scheduled_on->format('d/m/Y'));
    }

    public function test_scheduled_on_stays_null_when_the_order_has_no_day(): void
    {
        $order = Order::factory()->create();

        $this->assertNull($order->fresh()->scheduled_on);
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('scheduled_on'));
    }

    private function calendarDayIndex(): ?object
    {
        return DB::selectOne(
            "SELECT indexdef FROM pg_indexes
              WHERE schemaname = current_schema()
                AND tablename = 'orders'
                AND indexname = 'orders_tenant_id_scheduled_on_index'"
        );
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT data_type FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $row?->data_type;
    }
}

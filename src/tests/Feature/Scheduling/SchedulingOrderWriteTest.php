<?php

namespace Tests\Feature\Scheduling;

use App\Exceptions\SchedulingException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O pedido grava o dia que o servidor confirma. Relógio em 2026-11-09 15:00 UTC,
 * segunda-feira 12:00 em America/Sao_Paulo. Hoje nunca entra. Com antecedência 0,
 * a primeira candidata é 2026-11-10 (terça).
 */
class SchedulingOrderWriteTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private Tenant $tenant;

    private Product $product;

    private const CUSTOMER = ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '11999990000'];

    private const ADDRESS = ['line1' => 'Rua das Flores, 120', 'line2' => 'Apto 3', 'city' => 'Curitiba', 'state' => 'PR', 'postal_code' => '80010-000'];

    private const STOCK = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->service = new OrderService;
        $this->tenant = Tenant::factory()->create(['settings' => $this->fulfillmentSettings()]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 2000,
            'track_inventory' => true,
            'stock_quantity' => self::STOCK,
            'min_days_notice' => 0,
        ]);
    }

    public function test_disabled_scheduling_stores_pickup_and_delivery_without_a_day(): void
    {
        $schedule = [
            'date' => '2026-11-10',
            'option' => $this->timeOption('HORARIO-DO-CLIENTE'),
        ];

        foreach ([null, false] as $enabled) {
            $this->configure($enabled === null ? null : $this->scheduling(enabled: false));

            foreach ($this->fulfillments() as $fulfillment) {
                $order = $this->place($fulfillment, $schedule);
                $fresh = Order::withoutTenantScope()->findOrFail($order->id);

                $this->assertSame($fulfillment['type'], $fresh->fulfillment_type->value);
                $this->assertNull($fresh->scheduled_on);
                $this->assertNull($fresh->scheduled_for);
                $this->assertArrayNotHasKey('scheduling', $fresh->meta);
                $this->assertStringNotContainsString('HORARIO-DO-CLIENTE', (string) json_encode($fresh->meta));
                $this->assertStringNotContainsString('2026-11-10', (string) json_encode($fresh->meta));
            }
        }
    }

    public function test_enabled_scheduling_without_a_date_refuses_and_keeps_stock(): void
    {
        $this->configure($this->scheduling());

        foreach ($this->fulfillments() as $fulfillment) {
            $this->assertRefused($fulfillment, [], 'scheduled_on');
            $this->assertRefused($fulfillment, ['option' => $this->timeOption()], 'scheduled_on');
        }
    }

    public function test_a_date_without_a_registered_option_refuses_and_keeps_stock(): void
    {
        $this->configure($this->scheduling());

        foreach ($this->fulfillments() as $fulfillment) {
            $this->assertRefused($fulfillment, ['date' => '2026-11-10'], 'schedule_option');
            $this->assertRefused($fulfillment, [
                'date' => '2026-11-10',
                'option' => ['kind' => 'time', 'start' => '11:00', 'end' => null],
            ], 'schedule_option');
            $this->assertRefused($fulfillment, [
                'date' => '2026-11-10',
                'option' => ['kind' => 'time', 'start' => '10:00', 'end' => '11:00'],
            ], 'schedule_option');
        }
    }

    public function test_an_off_day_refuses_even_with_a_registered_option_and_keeps_stock(): void
    {
        $this->configure($this->scheduling(offDays: ['2026-11-10']));

        foreach ($this->fulfillments() as $fulfillment) {
            $this->assertRefused($fulfillment, [
                'date' => '2026-11-10',
                'option' => $this->timeOption(),
            ], 'scheduled_on');
        }
    }

    public function test_today_or_a_day_before_the_candidate_is_refused_and_keeps_stock(): void
    {
        $this->configure($this->scheduling());

        foreach (['2026-11-09', '2026-11-08'] as $date) {
            foreach ($this->fulfillments() as $fulfillment) {
                $this->assertRefused($fulfillment, [
                    'date' => $date,
                    'option' => $this->timeOption(),
                ], 'scheduled_on');
            }
        }
    }

    public function test_product_notice_refuses_a_day_the_store_window_would_accept(): void
    {
        $this->configure($this->scheduling());
        $this->product->update(['min_days_notice' => 5]);

        foreach ($this->fulfillments() as $fulfillment) {
            $this->assertRefused($fulfillment, [
                'date' => '2026-11-10',
                'option' => $this->timeOption(),
            ], 'scheduled_on');
        }
    }

    public function test_a_valid_time_stores_the_server_label_and_the_store_clock(): void
    {
        $this->configure($this->scheduling());

        $order = $this->place(['type' => 'pickup'], [
            'date' => '2026-11-10',
            'option' => $this->timeOption('HORARIO-DO-CLIENTE'),
        ]);
        $fresh = Order::withoutTenantScope()->findOrFail($order->id);

        $this->assertSame('2026-11-10', $fresh->scheduled_on->toDateString());
        $this->assertSame('2026-11-10', DB::table('orders')->where('id', $order->id)->value('scheduled_on'));
        $this->assertSame('2026-11-10', data_get($fresh->meta, 'scheduling.date'));
        $this->assertSame('time', data_get($fresh->meta, 'scheduling.option.kind'));
        $this->assertSame('10:00', data_get($fresh->meta, 'scheduling.option.start'));
        $this->assertNull(data_get($fresh->meta, 'scheduling.option.end'));
        $this->assertSame('10:00', data_get($fresh->meta, 'scheduling.option.label'));
        $this->assertEqualsCanonicalizing(
            ['kind', 'start', 'end', 'label'],
            array_keys((array) data_get($fresh->meta, 'scheduling.option')),
        );
        $this->assertStringNotContainsString('HORARIO-DO-CLIENTE', (string) json_encode($fresh->meta));
        $this->assertNotNull($fresh->scheduled_for);
        $this->assertTrue($fresh->scheduled_for->equalTo(Carbon::parse('2026-11-10 10:00:00', 'America/Sao_Paulo')));
    }

    public function test_a_valid_range_stores_the_en_dash_label_for_pickup_and_delivery(): void
    {
        $this->configure($this->scheduling(weekdayOverrides: [
            'tuesday' => [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                    ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
                ],
            ],
        ]));

        foreach ($this->fulfillments() as $fulfillment) {
            $order = $this->place($fulfillment, [
                'date' => '2026-11-10',
                'option' => [
                    'kind' => 'range',
                    'start' => '14:00',
                    'end' => '16:00',
                    'label' => 'TARDE-DO-CLIENTE',
                ],
            ]);
            $fresh = Order::withoutTenantScope()->findOrFail($order->id);

            $this->assertSame($fulfillment['type'], $fresh->fulfillment_type->value);
            $this->assertSame('2026-11-10', $fresh->scheduled_on->toDateString());
            $this->assertSame('range', data_get($fresh->meta, 'scheduling.option.kind'));
            $this->assertSame('14:00', data_get($fresh->meta, 'scheduling.option.start'));
            $this->assertSame('16:00', data_get($fresh->meta, 'scheduling.option.end'));
            $this->assertSame("14:00\u{2013}16:00", data_get($fresh->meta, 'scheduling.option.label'));
            $this->assertEqualsCanonicalizing(
                ['kind', 'start', 'end', 'label'],
                array_keys((array) data_get($fresh->meta, 'scheduling.option')),
            );
            $this->assertStringNotContainsString('TARDE-DO-CLIENTE', (string) json_encode($fresh->meta));
            $this->assertNotNull($fresh->scheduled_for);
            $this->assertTrue($fresh->scheduled_for->equalTo(Carbon::parse('2026-11-10 14:00:00', 'America/Sao_Paulo')));
        }
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, mixed>  $schedule
     */
    private function assertRefused(array $fulfillment, array $schedule, string $field): void
    {
        $ordersBefore = Order::withoutTenantScope()->count();
        $stockBefore = $this->stock();

        try {
            $this->place($fulfillment, $schedule);
            $this->fail('o pedido não pode nascer');
        } catch (SchedulingException $e) {
            $this->assertSame([$field], array_keys($e->errors));
            $this->assertNotSame('', $e->errors[$field]);
            $this->assertSame($e->errors[$field], $e->getMessage());
        }

        $this->assertSame($ordersBefore, Order::withoutTenantScope()->count());
        $this->assertSame($stockBefore, $this->stock());
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, mixed>  $schedule
     */
    private function place(array $fulfillment, array $schedule): Order
    {
        return $this->service->createFromCart(
            $this->tenant,
            self::CUSTOMER,
            [['product_id' => $this->product->id, 'qty' => 1]],
            'cash_on_delivery',
            [],
            $fulfillment,
            $schedule,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fulfillments(): array
    {
        return [
            ['type' => 'pickup'],
            ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS],
        ];
    }

    /**
     * @return array{kind: string, start: string, end: null, label?: string}
     */
    private function timeOption(?string $label = null): array
    {
        $option = ['kind' => 'time', 'start' => '10:00', 'end' => null];

        if ($label !== null) {
            $option['label'] = $label;
        }

        return $option;
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfillmentSettings(): array
    {
        return [
            'pickup' => ['enabled' => true, 'fee_cents' => 0],
            'delivery' => [
                'enabled' => true,
                'minimum_order_cents' => 0,
                'default_fee_cents' => 700,
                'free_delivery_above_cents' => 0,
                'regions' => [
                    ['id' => 'r-centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                    ['id' => 'r-alto', 'name' => 'Bairro Alto', 'fee_cents' => 900, 'active' => true],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array{enabled: bool, options: list<array<string, mixed>>}>  $weekdayOverrides
     * @param  list<string>  $offDays
     * @return array<string, mixed>
     */
    private function scheduling(bool $enabled = true, array $weekdayOverrides = [], array $offDays = []): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $days[$day] = $weekdayOverrides[$day] ?? [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ];
        }

        return [
            'enabled' => $enabled,
            'min_days' => 0,
            'max_days' => 30,
            'weekdays' => $days,
            'off_days' => $offDays,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $scheduling
     */
    private function configure(?array $scheduling): void
    {
        $settings = $this->fulfillmentSettings();

        if ($scheduling !== null) {
            $settings['scheduling'] = $scheduling;
        }

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }
}

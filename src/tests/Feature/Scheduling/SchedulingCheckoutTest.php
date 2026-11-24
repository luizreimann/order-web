<?php

namespace Tests\Feature\Scheduling;

use App\Livewire\Tenant\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use App\Support\TenantHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Checkout manual com agendamento. Relógio em 2026-11-09 15:00 UTC,
 * segunda-feira 12:00 em America/Sao_Paulo. Hoje nunca entra.
 * Com antecedência 0, a primeira candidata é 2026-11-10 (terça).
 */
class SchedulingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    private const ADDRESS = [
        'line1' => 'Rua das Flores, 120',
        'line2' => 'Apto 3',
        'city' => 'Curitiba',
        'state' => 'PR',
        'postal_code' => '80010-000',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));

        $this->tenant = Tenant::factory()->create([
            'slug' => 'loja-agenda',
            'is_active' => true,
            'settings' => $this->baseSettings(),
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 2500,
            'name' => 'Bolo',
            'min_days_notice' => 0,
            'requires_scheduling' => false,
        ]);
        $this->putInCart();
    }

    public function test_disabled_scheduling_closes_pickup_and_delivery_without_a_day(): void
    {
        foreach ([null, $this->scheduling(enabled: false)] as $block) {
            $this->configure($block);
            $this->putInCart();

            $html = $this->ready('pickup')->html();
            $this->assertStringNotContainsString('data-schedule-calendar', $html);
            $this->assertStringNotContainsString('type="date"', $html);

            foreach (['pickup', 'delivery'] as $type) {
                $order = $this->place($type, '2026-11-10', 'time|10:00|');

                $this->assertSame($type, $order->fulfillment_type->value);
                $this->assertNull($order->scheduled_on);
                $this->assertArrayNotHasKey('scheduling', $order->meta ?? []);
            }
        }
    }

    public function test_enabled_scheduling_refuses_pickup_and_delivery_without_a_day_or_an_option(): void
    {
        $this->configure($this->scheduling());

        foreach (['pickup', 'delivery'] as $type) {
            $this->putInCart();
            $before = $this->orders();

            $this->ready($type)->call('placeOrderManual')->assertHasErrors('scheduled_on');
            $this->assertSame($before, $this->orders());

            $this->ready($type)
                ->set('scheduled_on', '2026-11-10')
                ->call('placeOrderManual')
                ->assertHasErrors('schedule_option');
            $this->assertSame($before, $this->orders());
        }
    }

    public function test_the_select_lists_only_that_day_and_changing_the_day_clears_the_option(): void
    {
        $this->configure($this->scheduling(weekdayOverrides: [
            'tuesday' => [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                    ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
                ],
            ],
            'wednesday' => [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '09:00', 'end' => null],
                ],
            ],
        ]));

        $component = $this->ready('pickup')->set('scheduled_on', '2026-11-10');
        $select = $this->scheduleSelect($component->html());

        $this->assertSame(['time|10:00|', 'range|14:00|16:00'], $this->optionValues($select));
        $this->assertSame(['10:00', "14:00\u{2013}16:00"], $this->optionLabels($select));
        $this->assertStringNotContainsString('14:30', $select);
        $this->assertStringNotContainsString('type="date"', $component->html());
        $this->assertDoesNotMatchRegularExpression('/<(input|textarea)\b[^>]*scheduled_on/', $component->html());

        $component->set('schedule_option', 'range|14:00|16:00')
            ->set('scheduled_on', '2026-11-11')
            ->assertSet('scheduled_on', '2026-11-11')
            ->assertSet('schedule_option', '');

        $this->assertSame(['time|09:00|'], $this->optionValues($this->scheduleSelect($component->html())));
    }

    public function test_a_disabled_day_from_the_client_does_not_replace_the_marked_date_or_create_that_order(): void
    {
        $this->configure($this->scheduling(offDays: ['2026-11-12']));

        $empty = $this->ready('pickup');
        $empty->set('scheduled_on', '2026-11-09')->assertSet('scheduled_on', null);
        $empty->set('scheduled_on', '2026-11-12')->assertSet('scheduled_on', null);
        $empty->call('placeOrderManual')->assertHasErrors('scheduled_on');
        $this->assertSame(0, $this->orders());

        $component = $this->ready('pickup')
            ->set('scheduled_on', '2026-11-10')
            ->set('schedule_option', 'time|10:00|');

        $component->set('scheduled_on', '2026-11-09')
            ->assertSet('scheduled_on', '2026-11-10')
            ->assertSet('schedule_option', 'time|10:00|');
        $component->set('scheduled_on', '2026-11-12')
            ->assertSet('scheduled_on', '2026-11-10');

        $component->call('placeOrderManual')->assertHasNoErrors();

        $order = $this->newestOrder();
        $this->assertSame('2026-11-10', $order->scheduled_on?->toDateString());
        $this->assertSame(0, Order::withoutTenantScope()->whereIn('scheduled_on', ['2026-11-09', '2026-11-12'])->count());
    }

    public function test_a_more_demanding_cart_clears_the_day_that_became_too_early(): void
    {
        $this->configure($this->scheduling());

        $component = $this->ready('pickup')
            ->set('scheduled_on', '2026-11-10')
            ->set('schedule_option', 'time|10:00|');

        $this->product->update(['min_days_notice' => 5]);

        $component->call('$refresh')->assertSet('scheduled_on', null);
        $component->call('placeOrderManual')->assertHasErrors('scheduled_on');

        $this->assertSame(0, $this->orders());
        $this->assertSame(0, Order::withoutTenantScope()->where('scheduled_on', '2026-11-10')->count());
    }

    public function test_paused_store_and_a_closed_sunday_still_accept_the_scheduled_sunday(): void
    {
        $this->configure($this->scheduling(weekdayOverrides: [
            'sunday' => [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '11:00', 'end' => null],
                ],
            ],
        ]), [
            'business_hours' => [
                'enabled' => true,
                'paused' => true,
                'auto_close' => true,
                'timezone' => 'America/Sao_Paulo',
                'schedule' => [
                    'sunday' => ['closed' => true, 'open' => '08:00', 'close' => '18:00'],
                ],
            ],
        ]);

        $this->assertFalse(TenantHelper::isOpen($this->tenant));

        $calendar = $this->calendarFrom($this->ready('pickup')->html());
        $this->assertSame(['state' => 'open', 'reason' => null], $calendar['days']['2026-11-15']);

        $order = $this->place('pickup', '2026-11-15', 'time|11:00|');

        $this->assertSame('2026-11-15', $order->scheduled_on?->toDateString());
        $this->assertSame('11:00', data_get($order->meta, 'scheduling.option.start'));
    }

    public function test_the_old_requires_scheduling_flag_does_not_demand_a_day_while_the_block_is_off(): void
    {
        $this->product->update(['requires_scheduling' => true]);
        $this->configure($this->scheduling(enabled: false));

        $component = $this->ready('pickup');
        $this->assertStringNotContainsString('data-schedule-calendar', $component->html());

        $order = $this->place('pickup', '2026-11-10', 'time|10:00|');

        $this->assertTrue($this->product->fresh()->requires_scheduling);
        $this->assertNull($order->scheduled_on);
        $this->assertArrayNotHasKey('scheduling', $order->meta ?? []);
    }

    public function test_with_notice_one_the_rendered_payload_opens_on_wednesday(): void
    {
        $this->product->update(['min_days_notice' => null]);
        $this->configure($this->scheduling(min: 1), ['delivery' => ['enabled' => false]]);

        $html = $this->ready('pickup')->html();

        $this->assertStringContainsString('data-schedule-calendar', $html);
        $this->assertStringNotContainsString('id="deliveryFields"', $html);
        $this->assertStringNotContainsString('id="fulfillmentSection"', $html);
        $this->assertStringNotContainsString('type="date"', $html);

        $calendar = $this->calendarFrom($html);

        $this->assertSame('2026-11-11', $this->firstOpen($calendar));
        $this->assertSame('disabled', $calendar['days']['2026-11-09']['state']);
        $this->assertSame('disabled', $calendar['days']['2026-11-10']['state']);
    }

    private function ready(string $type): Testable
    {
        $component = Livewire::test(Checkout::class, ['tenant' => $this->tenant])
            ->set('customer_name', 'Ana Souza')
            ->set('customer_email', 'ana@example.com')
            ->set('customer_phone', '11999990000')
            ->set('payment_method', 'cash_on_delivery')
            ->set('fulfillment_type', $type);

        if ($type === 'delivery') {
            $component
                ->set('address_line1', self::ADDRESS['line1'])
                ->set('address_line2', self::ADDRESS['line2'])
                ->set('address_city', self::ADDRESS['city'])
                ->set('address_state', self::ADDRESS['state'])
                ->set('address_postal_code', self::ADDRESS['postal_code'])
                ->set('region_id', 'r-centro');
        }

        return $component;
    }

    private function place(string $type, ?string $date = null, ?string $option = null): Order
    {
        $this->putInCart();
        $component = $this->ready($type);

        if ($date !== null) {
            $component->set('scheduled_on', $date);
        }

        if ($option !== null) {
            $component->set('schedule_option', $option);
        }

        $component->call('placeOrderManual')->assertHasNoErrors()->assertRedirect();

        return $this->newestOrder();
    }

    private function putInCart(): void
    {
        app(CartService::class)->clear($this->tenant);
        app(CartService::class)->add($this->tenant, $this->product->id, 1);
    }

    private function orders(): int
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
    }

    private function newestOrder(): Order
    {
        return Order::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('number')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>|null  $scheduling
     * @param  array<string, mixed>  $extra
     */
    private function configure(?array $scheduling, array $extra = []): void
    {
        $settings = array_replace_recursive($this->baseSettings(), $extra);

        if ($scheduling === null) {
            unset($settings['scheduling']);
        } else {
            $settings['scheduling'] = $scheduling;
        }

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    /**
     * @param  array<string, array{enabled: bool, options: list<array<string, mixed>>}>  $weekdayOverrides
     * @param  list<string>  $offDays
     * @return array<string, mixed>
     */
    private function scheduling(bool $enabled = true, int $min = 0, int $max = 30, array $weekdayOverrides = [], array $offDays = []): array
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
            'min_days' => $min,
            'max_days' => $max,
            'weekdays' => $days,
            'off_days' => $offDays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSettings(): array
    {
        return [
            'payment' => ['methods' => [['id' => 'cash_on_delivery', 'provider' => 'manual', 'label' => 'Dinheiro', 'enabled' => true]]],
            'address' => ['city' => 'Curitiba', 'state' => 'pr'],
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
     * @return array<string, mixed>
     */
    private function calendarFrom(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-schedule-calendar>(.*?)<\/script>/s', $html, $match));

        $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $calendar
     */
    private function firstOpen(array $calendar): ?string
    {
        foreach ($calendar['days'] as $date => $day) {
            if (is_array($day) && ($day['state'] ?? null) === 'open') {
                return (string) $date;
            }
        }

        return null;
    }

    private function scheduleSelect(string $html): string
    {
        $this->assertSame(1, preg_match('/<select\b[^>]*\bid="scheduleOption"[^>]*>.*?<\/select>/s', $html, $match));

        return $match[0];
    }

    /**
     * @return list<string>
     */
    private function optionValues(string $select): array
    {
        preg_match_all('/<option\b[^>]*\bvalue="([^"]*)"/', $select, $matches);

        return array_values(array_filter($matches[1], fn (string $value): bool => $value !== ''));
    }

    /**
     * @return list<string>
     */
    private function optionLabels(string $select): array
    {
        preg_match_all('/<option\b[^>]*>([^<]*)</', $select, $matches);

        return array_values(array_filter(
            array_map(trim(...), $matches[1]),
            fn (string $label): bool => $label !== '' && $label !== 'Escolha o horário',
        ));
    }
}

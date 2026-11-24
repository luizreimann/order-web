<?php

namespace Tests\Feature\Scheduling;

use App\Contracts\PaymentResult;
use App\Gateways\MercadoPagoGateway;
use App\Gateways\PaymentGatewayManager;
use App\Livewire\Tenant\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

/**
 * Checkout online com agendamento. Relógio em 2026-11-09 15:00 UTC,
 * segunda-feira 12:00 em America/Sao_Paulo. Hoje nunca entra.
 * Com antecedência 0, a primeira candidata é 2026-11-10 (terça).
 *
 * O gateway é o falso, registrado só neste teste. O Mercado Pago não entra.
 */
class SchedulingOnlineCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'fake-secret';

    private const ADDRESS = [
        'line1' => 'Rua das Flores, 120',
        'line2' => 'Apto 3',
        'city' => 'Curitiba',
        'state' => 'PR',
        'postal_code' => '80010-000',
    ];

    private ScheduleProbeGateway $fake;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));

        $this->app->bind(MercadoPagoGateway::class, fn () => $this->fail('o checkout online de agendamento instanciou o MercadoPagoGateway'));
        Http::preventStrayRequests();

        $this->fake = new ScheduleProbeGateway;
        app(PaymentGatewayManager::class)->extend('fake', fn () => $this->fake);

        $this->tenant = Tenant::factory()->create([
            'slug' => 'agenda-loja',
            'is_active' => true,
            'settings' => $this->settings(true),
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 2500,
            'name' => 'Bolo',
            'track_inventory' => true,
            'stock_quantity' => 30,
            'min_days_notice' => 0,
            'requires_scheduling' => false,
        ]);
    }

    public function test_enabled_scheduling_refuses_pickup_and_delivery_on_pix_and_card_without_a_day_or_an_option(): void
    {
        $nonce = 0;

        foreach (['mp_pix', 'mp_credit_card'] as $method) {
            foreach (['pickup', 'delivery'] as $type) {
                foreach ([null, ['date' => '2026-11-10']] as $schedule) {
                    $field = $schedule === null ? 'scheduled_on' : 'schedule_option';
                    $message = $schedule === null
                        ? 'Escolha o dia da encomenda.'
                        : 'Escolha o horário da encomenda.';
                    $id = 'refuse-'.(++$nonce);

                    $this->assertUntouched(
                        fn () => $this->pay($method, $type, $schedule, $id),
                        $field,
                        $message,
                    );
                }
            }
        }

        $this->assertUntouched(
            fn () => $this->postPayment('not-an-object', ['schedule' => '2026-11-10']),
            'scheduled_on',
            'Escolha o dia da encomenda.',
        );
        $this->assertUntouched(
            fn () => $this->postPayment('date-not-string', [
                'schedule' => [
                    'date' => 20261110,
                    'option' => ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ]),
            'scheduled_on',
            'Escolha o dia da encomenda.',
        );
        $this->assertUntouched(
            fn () => $this->postPayment('nested-in-fulfillment', [
                'fulfillment' => [
                    'type' => 'pickup',
                    'schedule' => [
                        'date' => '2026-11-10',
                        'option' => ['kind' => 'time', 'start' => '10:00', 'end' => null],
                    ],
                ],
            ]),
            'scheduled_on',
            'Escolha o dia da encomenda.',
        );

        $this->assertSame([], $this->fake->charges);
        Http::assertNothingSent();
    }

    public function test_a_full_day_or_an_unknown_option_is_422_and_does_not_charge(): void
    {
        $this->assertUntouched(
            fn () => $this->pay('mp_pix', 'delivery', [
                'date' => '2026-11-10',
                'option' => ['kind' => 'time', 'start' => '09:00', 'end' => null, 'label' => 'ROTULO-DO-CLIENTE'],
            ], 'unknown-option'),
            'schedule_option',
            'Escolha o horário da encomenda.',
        );

        $this->configure(true, 1);

        $this->pay('mp_pix', 'pickup', $this->timeSchedule('2026-11-10'), 'full-first')->assertOk();
        $stock = $this->stock();
        $charges = count($this->fake->charges);

        $this->assertUntouched(
            fn () => $this->pay('mp_credit_card', 'delivery', $this->timeSchedule('2026-11-10'), 'full-second'),
            'scheduled_on',
            'Não há vaga neste dia.',
        );
        $this->assertSame($stock, $this->stock());
        $this->assertSame($charges, count($this->fake->charges));
        $this->assertSame(1, $this->orderCount());
        Http::assertNothingSent();
    }

    public function test_enabled_scheduling_stores_the_server_label_for_pix_and_card_on_pickup_and_delivery(): void
    {
        foreach (['mp_pix', 'mp_credit_card'] as $method) {
            foreach (['pickup', 'delivery'] as $type) {
                $this->pay($method, $type, $this->timeSchedule('2026-11-10', 'ROTULO-DO-CLIENTE'), "ok-{$method}-{$type}")
                    ->assertOk();

                $order = $this->newest();
                $this->assertSame($type, $order->fulfillment_type->value);
                $this->assertSame($method, $order->payment_method);
                $this->assertSame('2026-11-10', $order->scheduled_on?->toDateString());
                $this->assertEquals([
                    'date' => '2026-11-10',
                    'option' => [
                        'kind' => 'time',
                        'start' => '10:00',
                        'end' => null,
                        'label' => '10:00',
                    ],
                ], $order->meta['scheduling'] ?? null);
                $this->assertStringNotContainsString('ROTULO-DO-CLIENTE', (string) json_encode($order->meta));
            }
        }

        $this->pay('mp_pix', 'delivery', $this->rangeSchedule('2026-11-11'), 'ok-range')->assertOk();

        $range = $this->newest();
        $this->assertSame('2026-11-11', $range->scheduled_on?->toDateString());
        $this->assertEquals([
            'date' => '2026-11-11',
            'option' => [
                'kind' => 'range',
                'start' => '14:00',
                'end' => '16:00',
                'label' => "14:00\u{2013}16:00",
            ],
        ], $range->meta['scheduling'] ?? null);
        $this->assertStringNotContainsString('ROTULO-DO-CLIENTE', (string) json_encode($range->meta));
        $this->assertSame(5, count($this->fake->charges));
        Http::assertNothingSent();
    }

    public function test_disabled_scheduling_closes_without_schedule_and_ignores_a_sent_day_in_the_checkout_key(): void
    {
        foreach ([false, null] as $case => $enabled) {
            $this->configure($enabled);

            foreach (['pickup', 'delivery'] as $type) {
                $plainNonce = "off-{$case}-{$type}";
                $this->pay('mp_pix', $type, null, $plainNonce)->assertOk();

                $plain = $this->newest();
                $this->assertNull($plain->scheduled_on);
                $this->assertArrayNotHasKey('scheduling', $plain->meta ?? []);

                $sentNonce = $plainNonce.'-sent';
                $this->pay(
                    'mp_credit_card',
                    $type,
                    $this->timeSchedule('2026-11-10', 'ROTULO-DO-CLIENTE'),
                    $sentNonce,
                )->assertOk();

                $sent = $this->newest();
                $this->assertNull($sent->scheduled_on);
                $this->assertArrayNotHasKey('scheduling', $sent->meta ?? []);
                $this->assertStringNotContainsString('ROTULO-DO-CLIENTE', (string) json_encode($sent->meta));
                $this->assertStringNotContainsString('2026-11-10', (string) json_encode($sent->meta));

                $fulfillment = $this->acceptedFulfillment($type);
                $cart = [['product_id' => $this->product->id, 'qty' => 1]];
                $payments = app(PaymentService::class);
                $without = $payments->checkoutKey($this->tenant, $sentNonce, $cart, $fulfillment);
                $with = $payments->checkoutKey($this->tenant, $sentNonce, $cart, $fulfillment, $this->timeSchedule('2026-11-10', 'ROTULO-DO-CLIENTE'));
                $legacy = $this->expectedKey($sentNonce, $fulfillment, null);

                $this->assertSame($legacy, $without);
                $this->assertSame($without, $with);
                $this->assertSame($legacy, $this->idempotencyOf($sent));
                $this->assertSame($this->expectedKey($plainNonce, $fulfillment, null), $this->idempotencyOf($plain));
            }
        }

        Http::assertNothingSent();
    }

    public function test_another_day_or_option_changes_the_checkout_key_and_pix_reuses_it_with_card(): void
    {
        $cart = [['product_id' => $this->product->id, 'qty' => 1]];
        $pickup = $this->acceptedFulfillment('pickup');
        $payments = app(PaymentService::class);
        $time = $this->timeSchedule('2026-11-12', 'ROTULO-DO-CLIENTE');
        $later = $this->timeSchedule('2026-11-13');
        $range = $this->rangeSchedule('2026-11-12');

        $timeKey = $payments->checkoutKey($this->tenant, 'n', $cart, $pickup, $time);
        $laterKey = $payments->checkoutKey($this->tenant, 'n', $cart, $pickup, $later);
        $rangeKey = $payments->checkoutKey($this->tenant, 'n', $cart, $pickup, $range);
        $deliveryKey = $payments->checkoutKey($this->tenant, 'n', $cart, $this->acceptedFulfillment('delivery'), $time);

        $this->assertSame($this->expectedKey('n', $pickup, ['2026-11-12', 'time', '10:00', '']), $timeKey);
        $this->assertSame($this->expectedKey('n', $pickup, ['2026-11-13', 'time', '10:00', '']), $laterKey);
        $this->assertSame($this->expectedKey('n', $pickup, ['2026-11-12', 'range', '14:00', '16:00']), $rangeKey);
        $this->assertNotSame($timeKey, $laterKey);
        $this->assertNotSame($timeKey, $rangeKey);
        $this->assertNotSame($timeKey, $deliveryKey);
        $this->assertNotSame($this->expectedKey('n', $pickup, null), $timeKey);

        $first = $this->pay('mp_pix', 'pickup', $time, 'same-checkout')->assertOk();
        $second = $this->pay(
            'mp_credit_card',
            'pickup',
            $time,
            'same-checkout',
            ['name' => 'João', 'email' => 'joao@example.com', 'phone' => '11888887777'],
        )->assertOk();

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertSame('Maria', $this->newest()->customer_name);
        $this->assertCount(1, $this->fake->charges);
        $this->assertSame($this->expectedKey('same-checkout', $pickup, ['2026-11-12', 'time', '10:00', '']), $this->idempotencyOf($this->newest()));

        $otherDay = $this->pay('mp_pix', 'pickup', $later, 'same-checkout')->assertOk();
        $this->assertNotSame($first->json('order_id'), $otherDay->json('order_id'));
        $this->assertSame($this->expectedKey('same-checkout', $pickup, ['2026-11-13', 'time', '10:00', '']), $this->idempotencyOf($this->newest()));

        $otherOption = $this->pay('mp_credit_card', 'pickup', $range, 'same-checkout')->assertOk();
        $this->assertSame($this->expectedKey('same-checkout', $pickup, ['2026-11-12', 'range', '14:00', '16:00']), $this->idempotencyOf($this->newest()));
        $this->assertCount(3, $this->fake->charges);
        Http::assertNothingSent();
    }

    public function test_the_slot_lock_is_held_inside_begin_and_charge_runs_after_it_commits(): void
    {
        $outer = DB::transactionLevel();
        $events = [];

        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if (! str_contains($query->sql, 'pg_advisory_xact_lock')) {
                return;
            }

            $events[] = ['kind' => 'lock', 'level' => DB::transactionLevel(), 'bindings' => $query->bindings];
        });

        $this->fake->onCharge = function () use (&$events): void {
            $events[] = ['kind' => 'charge', 'level' => DB::transactionLevel()];
        };

        $this->pay('mp_pix', 'delivery', $this->timeSchedule('2026-11-10'), 'lock')->assertOk();

        $this->assertSame(['lock', 'charge'], array_column($events, 'kind'));
        $this->assertGreaterThan($outer, $events[0]['level']);
        $this->assertSame($outer, $events[1]['level']);
        $this->assertSame(['order-schedule', $this->tenant->id.'|2026-11-10'], $events[0]['bindings']);
        $this->assertSame(1, count($this->fake->charges));
        $this->assertSame('2026-11-10', $this->newest()->scheduled_on?->toDateString());
        Http::assertNothingSent();
    }

    public function test_pay_online_sends_schedule_beside_fulfillment_and_the_post_script_forwards_it(): void
    {
        app(CartService::class)->add($this->tenant, $this->product->id, 1);

        $this->checkout('mp_pix', 'pickup')
            ->call('payOnline')
            ->assertHasErrors('scheduled_on')
            ->assertNotDispatched('checkout-mp-pay');

        $this->checkout('mp_pix', 'delivery')
            ->set('scheduled_on', '2026-11-10')
            ->call('payOnline')
            ->assertHasErrors('schedule_option')
            ->assertNotDispatched('checkout-mp-pay');

        $this->checkout('mp_credit_card', 'delivery')
            ->set('scheduled_on', '2026-11-10')
            ->set('schedule_option', 'range|14:00|16:00')
            ->call('payOnline')
            ->assertHasNoErrors()
            ->assertDispatched('checkout-mp-pay', function (string $event, array $params): bool {
                $fulfillment = $params['fulfillment'] ?? null;

                return $event === 'checkout-mp-pay'
                    && ($params['method'] ?? null) === 'mp_credit_card'
                    && is_array($fulfillment)
                    && ($fulfillment['type'] ?? null) === 'delivery'
                    && ! array_key_exists('date', $fulfillment)
                    && ! array_key_exists('schedule', $fulfillment)
                    && ($params['schedule'] ?? null) === [
                        'date' => '2026-11-10',
                        'option' => ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
                    ];
            });

        $this->configure(false);
        app(CartService::class)->add($this->tenant, $this->product->id, 1);

        $this->checkout('mp_pix', 'pickup')
            ->call('payOnline')
            ->assertHasNoErrors()
            ->assertDispatched('checkout-mp-pay', function (string $event, array $params): bool {
                return $event === 'checkout-mp-pay' && ($params['schedule'] ?? null) === [];
            });

        $blade = file_get_contents(resource_path('views/livewire/tenant/checkout.blade.php'));
        $this->assertIsString($blade);
        $this->assertMatchesRegularExpression('/fulfillment:\s*detail\.fulfillment,\s*schedule:\s*detail\.schedule,/s', $blade);
        $this->assertSame(0, $this->orderCount());
        $this->assertSame([], $this->fake->charges);
    }

    /**
     * @param  callable(): TestResponse  $request
     */
    private function assertUntouched(callable $request, string $field, string $message): void
    {
        $orders = $this->orderCount();
        $stock = $this->stock();
        $charges = count($this->fake->charges);

        $response = $request();
        $response->assertStatus(422);
        $this->assertSame($message, $response->json('message'));
        $this->assertSame([$message], $response->json('errors.'.$field));
        $this->assertSame($orders, $this->orderCount());
        $this->assertSame($stock, $this->stock());
        $this->assertSame($charges, count($this->fake->charges));
    }

    /**
     * @param  array<string, mixed>|null  $schedule
     * @param  array{name: string, email: string, phone: string}|null  $customer
     */
    private function pay(string $method, string $type, ?array $schedule, string $nonce, ?array $customer = null): TestResponse
    {
        $body = [
            'mp_method' => $method,
            'customer' => $customer ?? ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            'formData' => [],
            'fulfillment' => $type === 'delivery'
                ? ['type' => 'delivery', 'region_id' => 'r-centro', 'address' => self::ADDRESS]
                : ['type' => 'pickup'],
        ];

        if ($schedule !== null) {
            $body['schedule'] = $schedule;
        }

        return $this->postPayment($nonce, $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postPayment(string $nonce, array $overrides): TestResponse
    {
        $body = array_merge([
            'mp_method' => 'mp_pix',
            'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            'formData' => [],
            'fulfillment' => ['type' => 'pickup'],
        ], $overrides);

        return $this->withSession($this->checkoutSession($nonce))->postJson('/agenda-loja/process_payment', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutSession(string $nonce): array
    {
        return [
            CartService::sessionKey($this->tenant) => [['product_id' => $this->product->id, 'qty' => 1]],
            'checkout.nonce.'.$this->tenant->id => $nonce,
        ];
    }

    private function checkout(string $method, string $type): Testable
    {
        $component = Livewire::test(Checkout::class, ['tenant' => $this->tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_phone', '11999990000')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', $method)
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

    /**
     * @return array{date: string, option: array{kind: string, start: string, end: null, label?: string}, note: string}
     */
    private function timeSchedule(string $date, ?string $label = null): array
    {
        $option = ['kind' => 'time', 'start' => '10:00', 'end' => null];

        if ($label !== null) {
            $option['label'] = $label;
        }

        return ['date' => $date, 'option' => $option, 'note' => 'ignore-me'];
    }

    /**
     * @return array{date: string, option: array{kind: string, start: string, end: string, label: string}}
     */
    private function rangeSchedule(string $date): array
    {
        return [
            'date' => $date,
            'option' => [
                'kind' => 'range',
                'start' => '14:00',
                'end' => '16:00',
                'label' => 'ROTULO-DO-CLIENTE',
            ],
        ];
    }

    /**
     * O que `fulfillmentFrom` guarda: pickup sem endereço vira string vazia, não some.
     *
     * @return array{type: string, region_id: string, address: array<string, string>}
     */
    private function acceptedFulfillment(string $type): array
    {
        if ($type === 'delivery') {
            return [
                'type' => 'delivery',
                'region_id' => 'r-centro',
                'address' => self::ADDRESS,
            ];
        }

        return [
            'type' => 'pickup',
            'region_id' => '',
            'address' => [
                'line1' => '',
                'line2' => '',
                'city' => '',
                'state' => '',
                'postal_code' => '',
            ],
        ];
    }

    /**
     * @param  array{type: string, region_id: string, address: array<string, string>}  $fulfillment
     * @param  list<string>|null  $scheduleParts
     */
    private function expectedKey(string $nonce, array $fulfillment, ?array $scheduleParts): string
    {
        $parts = [$this->tenant->id, $nonce, [[$this->product->id, 1]]];
        $address = $fulfillment['address'];
        ksort($address);
        $parts[] = [$fulfillment['type'], $fulfillment['region_id'], $address];

        if ($scheduleParts !== null) {
            $parts[] = $scheduleParts;
        }

        return substr(hash('sha256', (string) json_encode($parts)), 0, 40);
    }

    private function configure(?bool $enabled, ?int $capacity = null): void
    {
        $settings = $this->settings($enabled ?? false, $capacity);

        if ($enabled === null) {
            unset($settings['scheduling']);
        }

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(bool $enabled, ?int $capacity = null): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $block = [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                    ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
                ],
            ];

            if ($capacity !== null) {
                $block['capacity'] = $capacity;
            }

            $days[$day] = $block;
        }

        return [
            'payment' => [
                'fake' => ['secret' => self::SECRET],
                'methods' => [
                    ['id' => 'mp_pix', 'provider' => 'fake', 'label' => 'PIX', 'enabled' => true],
                    ['id' => 'mp_credit_card', 'provider' => 'fake', 'label' => 'Cartão', 'enabled' => true],
                ],
            ],
            'address' => ['city' => 'Curitiba', 'state' => 'pr'],
            'pickup' => ['enabled' => true, 'fee_cents' => 0],
            'delivery' => [
                'enabled' => true,
                'minimum_order_cents' => 0,
                'default_fee_cents' => 700,
                'free_delivery_above_cents' => 0,
                'regions' => [
                    ['id' => 'r-centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                ],
            ],
            'scheduling' => [
                'enabled' => $enabled,
                'min_days' => 0,
                'max_days' => 30,
                'weekdays' => $days,
                'off_days' => [],
            ],
        ];
    }

    private function orderCount(): int
    {
        return Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count();
    }

    private function newest(): Order
    {
        return Order::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('number')
            ->firstOrFail();
    }

    private function idempotencyOf(Order $order): string
    {
        $key = Payment::withoutTenantScope()->where('order_id', $order->id)->sole()->idempotency_key;
        $this->assertIsString($key);

        return $key;
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', $this->product->id)->value('stock_quantity');
    }
}

/**
 * O falso de teste, com o nível de transação no momento da cobrança.
 */
class ScheduleProbeGateway extends FakeGateway
{
    public ?\Closure $onCharge = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult
    {
        if ($this->onCharge !== null) {
            ($this->onCharge)();
        }

        return parent::createPayment($tenant, $order, $data);
    }
}

<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Role;
use App\Livewire\Admin\Orders\Show;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * A lista, a ficha e a conta leem o dia gravado. O rótulo é o do snapshot,
 * não a opção viva da loja. Pedido só com `scheduled_for` não muda de formato.
 */
class SchedulingOrderDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private const LABEL = "14:00\u{2013}16:00";

    private const DAY = '12/11/2026';

    private const PREVIOUS_DAY = '11/11/2026';

    private const STORED_CLOCK = '12/11/2026 17:00';

    private const CREATED_AT = '01/10/2026 08:15';

    private const LIVE_NOTE = 'HORARIO-VIVO';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'loja-agenda',
            'is_active' => true,
            'settings' => [
                'business_hours' => ['timezone' => 'America/Sao_Paulo'],
                'scheduling' => $this->schedulingBlock(),
            ],
        ]);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');

        $token = app(CustomerAuthService::class)->generateSessionToken();
        $this->customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ana',
            'phone' => '11988887777',
            'is_active' => true,
            'session_token' => $token,
        ]);

        $this->withCredentials()->withCookie(
            app(CustomerAuthService::class)->getCookieName($this->tenant),
            $token,
        );
    }

    public function test_the_stored_day_and_label_stay_after_the_store_option_is_removed(): void
    {
        $order = $this->order([
            'scheduled_on' => '2026-11-12',
            'scheduled_for' => '2026-11-12 17:00:00',
            'created_at' => '2026-10-01 08:15:00',
            'meta' => [
                'scheduling' => [
                    'date' => '2026-11-12',
                    'option' => [
                        'kind' => 'range',
                        'start' => '14:00',
                        'end' => '16:00',
                        'label' => self::LABEL,
                    ],
                ],
            ],
        ]);

        $this->assertSnapshotVisible($order);
        $this->assertShowDoesNotEditTheDay($this->show($order));

        $this->removeSchedulingOptions();

        $this->assertSame(self::LABEL, data_get($order->fresh()->meta, 'scheduling.option.label'));
        $this->assertSnapshotVisible($order);
        $this->assertStringNotContainsString(self::LIVE_NOTE, $this->list().$this->show($order).$this->account());
    }

    public function test_an_order_with_only_scheduled_for_keeps_the_clock_on_admin_and_created_at_on_the_account(): void
    {
        $order = $this->order([
            'scheduled_on' => null,
            'scheduled_for' => '2026-11-20 14:30:00',
            'created_at' => '2026-10-02 09:45:00',
            'meta' => [],
        ]);

        $clock = '20/11/2026 14:30';
        $createdAt = '02/10/2026 09:45';

        $this->assertStringContainsString($clock, $this->list());
        $this->assertStringContainsString($clock, $this->show($order));
        $this->assertStringNotContainsString(self::DAY, $this->list());
        $this->assertStringNotContainsString(self::LABEL, $this->show($order));

        $account = $this->account();
        $this->assertStringContainsString($createdAt, $account);
        $this->assertStringNotContainsString($clock, $account);
        $this->assertStringNotContainsString('20/11/2026', $account);
    }

    public function test_an_order_without_a_day_keeps_the_dash_on_the_list_and_created_at_on_the_account(): void
    {
        $this->order([
            'scheduled_on' => null,
            'scheduled_for' => null,
            'created_at' => '2026-10-03 11:05:00',
            'meta' => [],
        ]);

        $this->assertSame(1, substr_count($this->list(), "\u{2014}"));

        $account = $this->account();
        $this->assertStringContainsString('03/10/2026 11:05', $account);
        $this->assertStringNotContainsString(self::DAY, $account);
    }

    private function assertSnapshotVisible(Order $order): void
    {
        foreach ([$this->list(), $this->show($order), $this->account()] as $html) {
            $this->assertStringContainsString(self::DAY, $html);
            $this->assertStringContainsString(self::LABEL, $html);
            $this->assertStringNotContainsString(self::PREVIOUS_DAY, $html);
            $this->assertStringNotContainsString(self::STORED_CLOCK, $html);
            $this->assertStringNotContainsString(self::LIVE_NOTE, $html);
        }

        $this->assertStringContainsString(self::CREATED_AT, $this->account());
        $this->assertSame(0, substr_count($this->list(), "\u{2014}"));
    }

    private function assertShowDoesNotEditTheDay(string $html): void
    {
        $this->assertStringContainsString('wire:submit.prevent="updateStatus"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/wire:model(?:\.[A-Za-z]+)?="[^"]*(scheduled_on|scheduled_for|schedule_option)/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<(input|textarea|select)\b[^>]*\b(?:name|id)="(?:scheduled_on|scheduled_for|schedule_option)"/',
            $html,
        );

        $method = new ReflectionMethod(Show::class, 'updateStatus');
        $this->assertSame(
            ['orders'],
            array_map(fn (ReflectionParameter $parameter) => $parameter->getName(), $method->getParameters()),
        );
    }

    private function list(): string
    {
        return $this->get('/loja-agenda/admin/pedidos')->assertOk()->getContent();
    }

    private function show(Order $order): string
    {
        return $this->get('/loja-agenda/admin/pedidos/'.$order->id)->assertOk()->getContent();
    }

    private function account(): string
    {
        return $this->get('/loja-agenda/conta')->assertOk()->getContent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides): Order
    {
        return Order::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Ana',
            'customer_phone' => '11988887777',
            'payment_method' => 'cash_on_delivery',
            'fulfillment_type' => 'pickup',
        ], $overrides));
    }

    private function removeSchedulingOptions(): void
    {
        $settings = $this->tenant->fresh()->settings;

        foreach (array_keys($settings['scheduling']['weekdays']) as $day) {
            $settings['scheduling']['weekdays'][$day]['options'] = [];
        }

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();

        $this->assertSame([], data_get($this->tenant->settings, 'scheduling.weekdays.thursday.options'));
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulingBlock(): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $days[$day] = [
                'enabled' => true,
                'options' => [
                    [
                        'kind' => 'range',
                        'start' => '14:00',
                        'end' => '16:00',
                        'note' => self::LIVE_NOTE,
                    ],
                ],
            ];
        }

        return [
            'enabled' => true,
            'min_days' => 0,
            'max_days' => 30,
            'weekdays' => $days,
            'off_days' => [],
        ];
    }
}

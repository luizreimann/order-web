<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Role;
use App\Livewire\Admin\Tenant\ScheduleSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Configuração do bloco `scheduling`. O save troca o bloco inteiro e deixa taxa, bairro e horário.
 */
class ScheduleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->tenant = Tenant::factory()->create(['slug' => 'loja-a', 'is_active' => true]);
        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
    }

    private function screen(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ScheduleSettings::class, ['tenant' => $this->tenant]);
    }

    /** @return array<string, mixed> */
    private function siblings(): array
    {
        return [
            'delivery' => [
                'enabled' => true,
                'default_fee_cents' => 700,
                'regions' => [
                    ['id' => 'centro', 'name' => 'Centro', 'fee_cents' => 500, 'active' => true],
                ],
            ],
            'business_hours' => [
                'enabled' => true,
                'timezone' => 'America/Sao_Paulo',
                'paused' => false,
                'schedule' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                ],
            ],
        ];
    }

    public function test_the_first_open_starts_off_with_seven_checked_days(): void
    {
        $screen = $this->screen()
            ->assertSet('enabled', false)
            ->assertSet('min_days', 0)
            ->assertSet('max_days', 30)
            ->assertSet('off_days', []);

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $screen->assertSet("weekdays.{$day}.enabled", true)
                ->assertSet("weekdays.{$day}.capacity", '')
                ->assertSet("weekdays.{$day}.options", []);
        }
    }

    public function test_save_writes_scheduling_and_keeps_the_delivery_fee_and_business_hours(): void
    {
        $settings = $this->siblings();
        $settings['scheduling'] = [
            'enabled' => false,
            'min_days' => 1,
            'note' => 'lixo',
            'weekdays' => [],
            'off_days' => [],
        ];
        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();

        $this->screen()
            ->set('enabled', true)
            ->set('min_days', 2)
            ->set('max_days', 15)
            ->set('weekdays.monday.capacity', '')
            ->set('weekdays.tuesday.capacity', '0')
            ->set('weekdays.wednesday.capacity', '4')
            ->set('weekdays.sunday.enabled', false)
            ->call('addOption', 'thursday')
            ->set('weekdays.thursday.options.0.kind', 'time')
            ->set('weekdays.thursday.options.0.start', '00:00')
            ->call('addOption', 'thursday')
            ->set('weekdays.thursday.options.1.kind', 'range')
            ->set('weekdays.thursday.options.1.start', '23:00')
            ->set('weekdays.thursday.options.1.end', '23:59')
            ->call('save')
            ->assertHasNoErrors();

        $after = $this->tenant->refresh()->settings;

        $this->assertEquals($settings['delivery'], $after['delivery']);
        $this->assertEquals($settings['business_hours'], $after['business_hours']);
        $this->assertArrayNotHasKey('note', $after['scheduling']);
        $this->assertTrue($after['scheduling']['enabled']);
        $this->assertSame(2, $after['scheduling']['min_days']);
        $this->assertSame(15, $after['scheduling']['max_days']);
        $this->assertEqualsCanonicalizing(
            ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            array_keys($after['scheduling']['weekdays']),
        );
        $this->assertNull($after['scheduling']['weekdays']['monday']['capacity']);
        $this->assertSame(0, $after['scheduling']['weekdays']['tuesday']['capacity']);
        $this->assertSame(4, $after['scheduling']['weekdays']['wednesday']['capacity']);
        $this->assertFalse($after['scheduling']['weekdays']['sunday']['enabled']);
        $this->assertEquals([
            ['kind' => 'time', 'start' => '00:00', 'end' => null],
            ['kind' => 'range', 'start' => '23:00', 'end' => '23:59'],
        ], $after['scheduling']['weekdays']['thursday']['options']);
    }

    public function test_options_follow_the_order_the_store_sets(): void
    {
        $this->screen()
            ->call('addOption', 'tuesday')
            ->set('weekdays.tuesday.options.0.kind', 'time')
            ->set('weekdays.tuesday.options.0.start', '10:00')
            ->call('addOption', 'tuesday')
            ->set('weekdays.tuesday.options.1.kind', 'range')
            ->set('weekdays.tuesday.options.1.start', '14:00')
            ->set('weekdays.tuesday.options.1.end', '16:00')
            ->call('addOption', 'tuesday')
            ->set('weekdays.tuesday.options.2.kind', 'time')
            ->set('weekdays.tuesday.options.2.start', '18:00')
            ->call('moveOptionUp', 'tuesday', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals([
            ['kind' => 'time', 'start' => '10:00', 'end' => null],
            ['kind' => 'time', 'start' => '18:00', 'end' => null],
            ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
        ], $this->tenant->refresh()->settings['scheduling']['weekdays']['tuesday']['options']);

        $this->screen()
            ->call('moveOptionDown', 'tuesday', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals([
            ['kind' => 'time', 'start' => '10:00', 'end' => null],
            ['kind' => 'range', 'start' => '14:00', 'end' => '16:00'],
            ['kind' => 'time', 'start' => '18:00', 'end' => null],
        ], $this->tenant->refresh()->settings['scheduling']['weekdays']['tuesday']['options']);
    }

    public function test_removing_an_option_keeps_the_order_of_the_rest(): void
    {
        $this->screen()
            ->call('addOption', 'friday')
            ->set('weekdays.friday.options.0.kind', 'time')
            ->set('weekdays.friday.options.0.start', '08:00')
            ->call('addOption', 'friday')
            ->set('weekdays.friday.options.1.kind', 'time')
            ->set('weekdays.friday.options.1.start', '09:00')
            ->call('removeOption', 'friday', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals([
            ['kind' => 'time', 'start' => '09:00', 'end' => null],
        ], $this->tenant->refresh()->settings['scheduling']['weekdays']['friday']['options']);
    }

    public function test_a_range_that_does_not_end_after_it_starts_does_not_save(): void
    {
        $settings = $this->siblings();
        $settings['scheduling'] = [
            'enabled' => true,
            'min_days' => 3,
            'max_days' => 9,
            'kept' => 'sim',
            'weekdays' => [],
            'off_days' => ['2026-12-24'],
        ];
        $this->tenant->update(['settings' => $settings]);
        $before = $this->tenant->refresh()->settings;

        $screen = $this->screen()
            ->call('addOption', 'monday')
            ->set('weekdays.monday.options.0.kind', 'range')
            ->set('weekdays.monday.options.0.start', '16:00')
            ->set('weekdays.monday.options.0.end', '10:00')
            ->call('save')
            ->assertHasErrors('weekdays.monday.options.0.end');

        $this->assertSame($before, $this->tenant->refresh()->settings);

        $screen->set('weekdays.monday.options.0.end', '16:00')
            ->call('save')
            ->assertHasErrors('weekdays.monday.options.0.end');

        $this->assertSame($before, $this->tenant->refresh()->settings);
    }

    public function test_a_time_with_an_end_or_an_hour_outside_the_clock_does_not_save(): void
    {
        $screen = $this->screen()
            ->call('addOption', 'monday')
            ->set('weekdays.monday.options.0.kind', 'time')
            ->set('weekdays.monday.options.0.start', '10:00')
            ->set('weekdays.monday.options.0.end', '11:00')
            ->call('save')
            ->assertHasErrors('weekdays.monday.options.0.end');

        $this->assertArrayNotHasKey('scheduling', $this->tenant->refresh()->settings);

        $screen->set('weekdays.monday.options.0.end', '')
            ->set('weekdays.monday.options.0.start', '24:00')
            ->call('save')
            ->assertHasErrors('weekdays.monday.options.0.start');

        $screen->set('weekdays.monday.options.0.start', '9:00')
            ->call('save')
            ->assertHasErrors('weekdays.monday.options.0.start');

        $this->assertArrayNotHasKey('scheduling', $this->tenant->refresh()->settings);
    }

    public function test_a_missing_weekday_comes_back_checked_without_hours_or_a_limit(): void
    {
        $screen = $this->screen();
        $weekdays = $screen->get('weekdays');
        unset($weekdays['tuesday']);

        $screen->set('weekdays', $weekdays)->call('save')->assertHasNoErrors();

        $this->assertEquals([
            'enabled' => true,
            'capacity' => null,
            'options' => [],
        ], $this->tenant->refresh()->settings['scheduling']['weekdays']['tuesday']);
    }

    public function test_an_off_day_is_added_once_from_the_calendar_and_removing_it_returns_the_day(): void
    {
        $screen = $this->screen()
            ->call('addOffDay', '2026-12-25')
            ->call('addOffDay', '2026-12-25')
            ->call('addOffDay', '25/12/2026')
            ->call('addOffDay', '2026-02-31')
            ->call('addOffDay', '')
            ->assertSet('off_days', ['2026-12-25'])
            ->call('addOffDay', '2026-01-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['2026-12-25', '2026-01-01'], $this->tenant->refresh()->settings['scheduling']['off_days']);

        $screen->call('removeOffDay', '2026-12-25')
            ->assertSet('off_days', ['2026-01-01'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['2026-01-01'], $this->tenant->refresh()->settings['scheduling']['off_days']);
    }

    public function test_the_off_day_mount_has_no_text_or_date_input(): void
    {
        $html = $this->screen()->call('addOffDay', '2026-12-25')->html();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-schedule-off-day[^>]*>.*?<\/div>/s', $html, $mount));
        $this->assertStringNotContainsString('<input', $mount[0]);
        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertStringNotContainsString('type="text"', $mount[0]);

        $source = file_get_contents(resource_path('views/livewire/admin/tenant/schedule-settings.blade.php'));
        $this->assertIsString($source);
        $from = strpos($source, 'data-schedule-off-day');
        $to = strpos($source, 'Salvar');
        $this->assertNotFalse($from);
        $this->assertNotFalse($to);
        $chunk = substr($source, $from, $to - $from);
        $this->assertStringNotContainsString('<input', $chunk);
        $this->assertStringNotContainsString('type="date"', $source);
        $this->assertStringNotContainsString('type="text"', $chunk);
    }

    public function test_the_page_is_available_to_who_already_configures_the_store(): void
    {
        $this->get(route('tenant.admin.scheduling', ['tenant' => 'loja-a']))
            ->assertOk()
            ->assertSee('Agendamento')
            ->assertSee('data-schedule-off-day', false);
    }

    public function test_an_admin_of_another_store_is_redirected_away(): void
    {
        $other = Tenant::factory()->create(['is_active' => true]);
        $this->actingAs(User::factory()->create([
            'tenant_id' => $other->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');

        $this->get(route('tenant.admin.scheduling', ['tenant' => 'loja-a']))->assertStatus(302);
    }
}

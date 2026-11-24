<?php

namespace Tests\Feature\Scheduling;

use App\Models\Tenant;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * O mês que a tela pinta, sem biblioteca. O relógio está em
 * 2026-11-09 15:00 UTC, segunda-feira 12:00 em America/Sao_Paulo.
 * Hoje (2026-11-09) nunca abre. Os sete dias vêm marcados, com 10:00,
 * salvo quando o caso diz o contrário. A view só afirma o ponto de
 * montagem: o teste não clica no widget.
 */
class SchedulingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private SchedulingService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->service = new SchedulingService;
        $this->tenant = Tenant::factory()->create();
    }

    public function test_the_week_starts_on_sunday_and_november_is_in_portuguese(): void
    {
        $this->configure(0, 30);

        $calendar = $this->calendar();

        $this->assertSame('sunday', $calendar['week_starts_on']);
        $this->assertSame('pt', $calendar['locale']);
        $this->assertSame('novembro', $calendar['month_names']['2026-11']);
    }

    public function test_the_candidate_is_open_and_today_stays_outside_the_window(): void
    {
        $this->configure(0, 30);

        $days = $this->calendar()['days'];

        $this->assertSame('2026-11-10', $this->service->window($this->tenant)['candidate']);
        $this->assertSame(['state' => 'open', 'reason' => null], $days['2026-11-10']);
        $this->assertSame(['state' => 'disabled', 'reason' => 'window'], $days['2026-11-09']);
    }

    public function test_an_unchecked_sunday_is_weekday_and_not_open(): void
    {
        $this->configure(0, 30, $this->weekdays([
            'sunday' => [
                'enabled' => false,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ],
        ]));

        $days = $this->calendar()['days'];
        $sundays = 0;

        foreach ($days as $date => $day) {
            if (Carbon::parse($date)->dayOfWeek !== Carbon::SUNDAY) {
                continue;
            }

            $sundays++;
            $this->assertSame(['state' => 'disabled', 'reason' => 'weekday'], $day, $date);
        }

        $this->assertGreaterThan(0, $sundays);
        $this->assertSame(['state' => 'open', 'reason' => null], $days['2026-11-10']);
    }

    public function test_a_marked_tuesday_without_options_is_no_option(): void
    {
        $this->configure(0, 30, $this->weekdays([
            'tuesday' => [
                'enabled' => true,
                'options' => [],
            ],
        ]));

        $days = $this->calendar()['days'];
        $tuesdays = 0;

        foreach ($days as $date => $day) {
            if (Carbon::parse($date)->dayOfWeek !== Carbon::TUESDAY) {
                continue;
            }

            $tuesdays++;
            $this->assertSame(['state' => 'disabled', 'reason' => 'no_option'], $day, $date);
        }

        $this->assertGreaterThan(0, $tuesdays);
        $this->assertSame(['state' => 'open', 'reason' => null], $days['2026-11-11']);
    }

    public function test_an_off_day_is_exact_and_does_not_repeat_next_year(): void
    {
        // 365 alcança 2027-11-09. 2027-11-10 entra no mês visível, fora da janela.
        $this->configure(0, 365, $this->weekdays([
            'tuesday' => [
                'enabled' => false,
                'options' => [],
            ],
        ]), ['2026-11-10']);

        $calendar = $this->calendar();
        $window = $this->service->window($this->tenant);

        $this->assertSame('2026-11-10', $window['candidate']);
        $this->assertSame('2027-11-09', $window['last']);
        $this->assertSame(['state' => 'disabled', 'reason' => 'off'], $calendar['days']['2026-11-10']);
        $this->assertSame(['state' => 'disabled', 'reason' => 'no_option'], $calendar['days']['2026-11-17']);
        $this->assertSame(['state' => 'open', 'reason' => null], $calendar['days']['2026-11-11']);
        $this->assertSame(['state' => 'disabled', 'reason' => 'window'], $calendar['days']['2027-11-10']);
        $this->assertNotSame('off', $calendar['days']['2027-11-10']['reason']);
    }

    public function test_two_calls_without_a_fulfillment_argument_return_the_same_payload(): void
    {
        $this->configure(0, 30);

        $method = new ReflectionMethod(SchedulingService::class, 'calendar');
        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );

        $this->assertSame(['tenant', 'products'], $names);
        $this->assertSame(1, $method->getNumberOfRequiredParameters());
        $this->assertSame($this->service->calendar($this->tenant), $this->service->calendar($this->tenant));
    }

    public function test_visible_months_stop_at_the_candidate_month_and_the_last_month(): void
    {
        $this->configure(0, 30);

        $calendar = $this->calendar();

        $this->assertSame('2026-11', $calendar['first_month']);
        $this->assertSame('2026-12', $calendar['last_month']);
        $this->assertSame([
            '2026-11' => 'novembro',
            '2026-12' => 'dezembro',
        ], $calendar['month_names']);
        $this->assertSame('2026-11-01', array_key_first($calendar['days']));
        $this->assertSame('2026-12-31', array_key_last($calendar['days']));
        $this->assertArrayNotHasKey('2026-10-31', $calendar['days']);
        $this->assertArrayNotHasKey('2027-01-01', $calendar['days']);

        foreach ($calendar['days'] as $date => $day) {
            if ($date < '2026-11-10' || $date > '2026-12-09') {
                $this->assertSame(['state' => 'disabled', 'reason' => 'window'], $day, $date);

                continue;
            }

            $this->assertSame(['state' => 'open', 'reason' => null], $day, $date);
        }
    }

    public function test_min_one_and_max_one_offer_no_day_and_no_months(): void
    {
        $this->configure(1, 1);

        $calendar = $this->calendar();

        $this->assertNull($calendar['first_month']);
        $this->assertNull($calendar['last_month']);
        $this->assertSame([], $calendar['month_names']);
        $this->assertSame([], $calendar['days']);
        $this->assertSame('sunday', $calendar['week_starts_on']);
        $this->assertSame('pt', $calendar['locale']);
    }

    public function test_the_checkout_view_mounts_the_calendar_without_a_date_field(): void
    {
        $source = file_get_contents(resource_path('views/livewire/tenant/checkout.blade.php'));

        $this->assertIsString($source);

        $from = strpos($source, 'id="scheduleSection"');
        $to = strpos($source, 'id="scheduleOption"');

        $this->assertNotFalse($from);
        $this->assertNotFalse($to);

        $section = substr($source, $from, $to - $from);

        $this->assertStringContainsString('data-schedule-calendar', $section);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-schedule-calendar-mount[^>]*>\s*<\/div>/', $section, $mount));
        $this->assertStringNotContainsString('wire:ignore', $mount[0]);
        $this->assertStringNotContainsString('<input', $section);
        $this->assertStringNotContainsString('<textarea', $section);
        $this->assertStringNotContainsString('type="date"', $source);
        $this->assertStringNotContainsString('type="text"', $section);
    }

    /**
     * @param  array<string, array{enabled: bool, options: list<array{kind: string, start: string, end: null}>}>  $overrides
     * @return array<string, array{enabled: bool, options: list<array{kind: string, start: string, end: null}>}>
     */
    private function weekdays(array $overrides = []): array
    {
        $days = [];

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
            $days[$day] = $overrides[$day] ?? [
                'enabled' => true,
                'options' => [
                    ['kind' => 'time', 'start' => '10:00', 'end' => null],
                ],
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, array{enabled: bool, options: list<array{kind: string, start: string, end: null}>}>|null  $weekdays
     * @param  list<string>  $offDays
     */
    private function configure(int $minDays, int $maxDays, ?array $weekdays = null, array $offDays = []): void
    {
        $this->tenant->update([
            'settings' => [
                'scheduling' => [
                    'enabled' => true,
                    'min_days' => $minDays,
                    'max_days' => $maxDays,
                    'weekdays' => $weekdays ?? $this->weekdays(),
                    'off_days' => $offDays,
                ],
            ],
        ]);
        $this->tenant->refresh();
    }

    /**
     * @return array{
     *     week_starts_on: string,
     *     locale: string,
     *     month_names: array<string, string>,
     *     first_month: ?string,
     *     last_month: ?string,
     *     days: array<string, array{state: string, reason: ?string}>
     * }
     */
    private function calendar(): array
    {
        return $this->service->calendar($this->tenant);
    }
}

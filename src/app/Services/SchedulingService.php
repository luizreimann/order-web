<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\SchedulingException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Janela e calendário de agendamento da loja. A tela não soma antecedência nem escolhe o fuso.
 *
 * A primeira candidata é hoje + antecedência + 1 dia de calendário. A última é hoje +
 * `scheduling.max_days`. Hoje nunca entra. A contagem não pula folga nem dia sem entrega.
 * `min_days_notice` null no produto usa `scheduling.min_days`; 0 substitui. O carrinho fica
 * com a maior. Expediente da vitrine (`business_hours.enabled`) não entra nesta conta.
 *
 * `calendar()` lista cada dia do mês da candidata até o mês da última. A candidata continua
 * a de `window()`. O primeiro dia aberto é o próximo válido, para a frente. Lotado entra
 * sem lock: a tela pode estar um instante atrasada.
 *
 * `confirm()` aceita o dia do pedido quando o bloco está ligado. Desligado, devolve null.
 * Reusa `window()` e a mesma regra do calendário. Dentro da transação do pedido, trava a
 * data e conta a vaga de novo antes de gravar.
 */
class SchedulingService
{
    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    private const DAY_LIMIT = 365;

    /** @var array<int, string> */
    private const WEEKDAY_KEYS = [
        Carbon::SUNDAY => 'sunday',
        Carbon::MONDAY => 'monday',
        Carbon::TUESDAY => 'tuesday',
        Carbon::WEDNESDAY => 'wednesday',
        Carbon::THURSDAY => 'thursday',
        Carbon::FRIDAY => 'friday',
        Carbon::SATURDAY => 'saturday',
    ];

    /** @var array<int, string> */
    private const MONTH_NAMES = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'março',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    /**
     * @param  list<Product>  $products  vazio: vale só a loja
     * @return array{notice_days: int, candidate: ?string, last: ?string, has_day: bool}
     */
    public function window(Tenant $tenant, array $products = []): array
    {
        $noticeDays = $this->noticeDays($tenant, $products);
        $today = Carbon::now($this->timezone($tenant))->startOfDay();
        $candidate = $today->copy()->addDays($noticeDays + 1)->toDateString();
        $last = $today->copy()->addDays($this->maxDays($tenant))->toDateString();

        if ($candidate > $last) {
            return [
                'notice_days' => $noticeDays,
                'candidate' => null,
                'last' => null,
                'has_day' => false,
            ];
        }

        return [
            'notice_days' => $noticeDays,
            'candidate' => $candidate,
            'last' => $last,
            'has_day' => true,
        ];
    }

    /**
     * Mês visível da candidata até a última. Entrega e retirada são o mesmo calendário.
     * A vaga entra sem lock.
     *
     * @param  list<Product>  $products  vazio: vale só a loja
     * @return array{
     *     week_starts_on: 'sunday',
     *     locale: 'pt',
     *     month_names: array<string, string>,
     *     first_month: ?string,
     *     last_month: ?string,
     *     days: array<string, array{state: 'open'|'disabled', reason: null|'off'|'full'|'no_option'|'weekday'|'window'}>
     * }
     */
    public function calendar(Tenant $tenant, array $products = []): array
    {
        $window = $this->window($tenant, $products);
        $candidate = $window['candidate'];
        $last = $window['last'];

        if ($candidate === null || $last === null) {
            return $this->blankCalendar();
        }

        $timezone = $this->timezone($tenant);
        $offDays = $this->offDays($tenant);
        $weekdays = $this->weekdayRules($tenant);
        $cursor = Carbon::parse($candidate, $timezone)->startOfMonth();
        $end = Carbon::parse($last, $timezone)->endOfMonth()->startOfDay();
        $occupied = $this->occupiedByDate($tenant, $cursor->toDateString(), $end->toDateString());
        $days = [];

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $days[$date] = $this->dayCell(
                $date,
                $this->weekdayKey($cursor->dayOfWeek),
                $candidate,
                $last,
                $offDays,
                $weekdays,
                $occupied[$date] ?? 0,
            );
            $cursor->addDay();
        }

        return [
            'week_starts_on' => 'sunday',
            'locale' => 'pt',
            'month_names' => $this->monthNames($candidate, $last, $timezone),
            'first_month' => substr($candidate, 0, 7),
            'last_month' => substr($last, 0, 7),
            'days' => $days,
        ];
    }

    /**
     * Dia e opção que o pedido pode gravar. Bloco ausente ou desligado devolve null:
     * o que o cliente mandou não entra. O `label` enviado é ignorado.
     *
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $schedule  `date` e `option` (`kind`, `start`, `end`)
     * @return array{
     *     scheduled_on: string,
     *     scheduled_for: Carbon,
     *     snapshot: array{date: string, option: array{kind: string, start: string, end: string|null, label: string}}
     * }|null
     *
     * @throws SchedulingException
     */
    public function confirm(Tenant $tenant, array $products, array $schedule): ?array
    {
        if (data_get($tenant->settings, 'scheduling.enabled') !== true) {
            return null;
        }

        $timezone = $this->timezone($tenant);
        $parsed = $this->calendarDay($schedule['date'] ?? null, $timezone);

        if ($parsed === null) {
            throw SchedulingException::field('scheduled_on', 'Escolha o dia da encomenda.');
        }

        $date = $parsed->toDateString();
        $weekday = $this->weekdayKey($parsed->dayOfWeek);

        if (! $this->dayIsOpen($tenant, $products, $date, $weekday)) {
            throw SchedulingException::field('scheduled_on', 'Este dia não está disponível.');
        }

        $option = $this->matchingOption($tenant, $weekday, $schedule['option'] ?? null);

        if ($option === null) {
            throw SchedulingException::field('schedule_option', 'Escolha o horário da encomenda.');
        }

        $scheduledFor = Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$option['start'], $timezone);

        if ($scheduledFor === null) {
            throw SchedulingException::field('schedule_option', 'Escolha o horário da encomenda.');
        }

        // Já dentro da transação de createFromCart. Conta de novo sob o lock, antes de gravar.
        $this->lockDate($tenant->id, $date);

        if ($this->isFull($tenant, $date)) {
            throw SchedulingException::field('scheduled_on', 'Não há vaga neste dia.');
        }

        return [
            'scheduled_on' => $date,
            'scheduled_for' => $scheduledFor->utc(),
            'snapshot' => [
                'date' => $date,
                'option' => $option,
            ],
        ];
    }

    /**
     * Trava o dia na transação de `updateOrder` quando o status entra ou sai de `cancelled`.
     * Cancelar só trava. Reabrir no limite lança no campo `status` e não grava.
     *
     * Só chame dentro de transação: o lock cai no fim dela.
     *
     * @throws SchedulingException
     */
    public function lockCancelledTransition(Tenant $tenant, string $date, bool $reopening): void
    {
        $this->lockDate($tenant->id, $date);

        if ($reopening && $this->isFull($tenant, $date)) {
            throw SchedulingException::field('status', 'Não há vaga neste dia.');
        }
    }

    /**
     * O dia ainda sem pedido não tem linha para travar. O lock de transação segura esse buraco
     * até o commit. Fora de transação ele se solta no fim desta sentença.
     */
    private function lockDate(string $tenantId, string $date): void
    {
        DB::select(
            'select pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            ['order-schedule', $tenantId.'|'.$date],
        );
    }

    /**
     * @param  list<Product>  $products
     */
    private function noticeDays(Tenant $tenant, array $products): int
    {
        $storeMin = $this->minDays($tenant);

        if ($products === []) {
            return $storeMin;
        }

        $notices = [];

        foreach ($products as $product) {
            $own = $product->min_days_notice;
            $notices[] = $own === null ? $storeMin : $this->boundedDays($own, $storeMin);
        }

        return (int) max($notices);
    }

    private function minDays(Tenant $tenant): int
    {
        return $this->boundedDays(data_get($tenant->settings, 'scheduling.min_days'), 0);
    }

    private function maxDays(Tenant $tenant): int
    {
        return $this->boundedDays(data_get($tenant->settings, 'scheduling.max_days'), 30);
    }

    /** Inteiro na faixa 0–365. O que não é inteiro conta como ausente e usa o padrão. */
    private function boundedDays(mixed $value, int $default): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return $default;
        }

        return min(self::DAY_LIMIT, max(0, (int) $value));
    }

    /**
     * @return array{
     *     week_starts_on: 'sunday',
     *     locale: 'pt',
     *     month_names: array<string, string>,
     *     first_month: null,
     *     last_month: null,
     *     days: array{}
     * }
     */
    private function blankCalendar(): array
    {
        return [
            'week_starts_on' => 'sunday',
            'locale' => 'pt',
            'month_names' => [],
            'first_month' => null,
            'last_month' => null,
            'days' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function monthNames(string $candidate, string $last, string $timezone): array
    {
        $month = Carbon::parse($candidate, $timezone)->startOfMonth();
        $limit = Carbon::parse($last, $timezone)->startOfMonth();
        $names = [];

        while ($month->lte($limit)) {
            $names[$month->format('Y-m')] = $this->monthName((int) $month->format('n'));
            $month->addMonth();
        }

        return $names;
    }

    private function monthName(int $month): string
    {
        return self::MONTH_NAMES[$month] ?? '';
    }

    private function weekdayKey(int $dayOfWeek): string
    {
        return self::WEEKDAY_KEYS[$dayOfWeek] ?? 'sunday';
    }

    /**
     * Datas Y-m-d exatas. Não se repetem no ano seguinte.
     *
     * @return array<string, true>
     */
    private function offDays(Tenant $tenant): array
    {
        $raw = data_get($tenant->settings, 'scheduling.off_days');

        if (! is_array($raw)) {
            return [];
        }

        $days = [];

        foreach ($raw as $day) {
            if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $days[$day] = true;
            }
        }

        return $days;
    }

    /**
     * Dia ausente nasce marcado, sem horário e sem limite: sem item, o motivo é `no_option`.
     *
     * @return array<string, array{enabled: bool, has_option: bool, capacity: ?int}>
     */
    private function weekdayRules(Tenant $tenant): array
    {
        $rules = [];

        foreach (self::WEEKDAY_KEYS as $key) {
            $enabled = data_get($tenant->settings, "scheduling.weekdays.{$key}.enabled");
            $options = data_get($tenant->settings, "scheduling.weekdays.{$key}.options");

            $rules[$key] = [
                'enabled' => $enabled !== false,
                'has_option' => is_array($options) && $options !== [],
                'capacity' => $this->capacityLimit(data_get($tenant->settings, "scheduling.weekdays.{$key}.capacity")),
            ];
        }

        return $rules;
    }

    /**
     * Um motivo, nesta ordem: folga, lotado, sem opção, dia desmarcado, fora da janela.
     * `$occupied` null não aplica vaga: `confirm()` trava e conta de novo.
     *
     * @param  array<string, true>  $offDays
     * @param  array<string, array{enabled: bool, has_option: bool, capacity: ?int}>  $weekdays
     * @return array{state: 'open'|'disabled', reason: null|'off'|'full'|'no_option'|'weekday'|'window'}
     */
    private function dayCell(string $date, string $weekday, string $candidate, string $last, array $offDays, array $weekdays, ?int $occupied): array
    {
        if (isset($offDays[$date])) {
            return ['state' => 'disabled', 'reason' => 'off'];
        }

        $rule = $weekdays[$weekday] ?? ['enabled' => true, 'has_option' => false, 'capacity' => null];

        if ($occupied !== null && $rule['capacity'] !== null && $occupied >= $rule['capacity']) {
            return ['state' => 'disabled', 'reason' => 'full'];
        }

        if (! $rule['has_option']) {
            return ['state' => 'disabled', 'reason' => 'no_option'];
        }

        if (! $rule['enabled']) {
            return ['state' => 'disabled', 'reason' => 'weekday'];
        }

        if ($date < $candidate || $date > $last) {
            return ['state' => 'disabled', 'reason' => 'window'];
        }

        return ['state' => 'open', 'reason' => null];
    }

    private function calendarDay(mixed $date, string $timezone): ?Carbon
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = Carbon::createFromFormat('!Y-m-d', $date, $timezone);

        if ($parsed === null || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $parsed;
    }

    /**
     * Aberto para gravar, sem vaga. A vaga é contada de novo sob o lock.
     *
     * @param  list<Product>  $products
     */
    private function dayIsOpen(Tenant $tenant, array $products, string $date, string $weekday): bool
    {
        $window = $this->window($tenant, $products);
        $candidate = $window['candidate'];
        $last = $window['last'];

        if ($candidate === null || $last === null) {
            return false;
        }

        $cell = $this->dayCell($date, $weekday, $candidate, $last, $this->offDays($tenant), $this->weekdayRules($tenant), null);

        return $cell['state'] === 'open';
    }

    /** `null` não limita. Inteiro negativo vira 0. O que não é inteiro conta como ausente. */
    private function capacityLimit(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function isFull(Tenant $tenant, string $date): bool
    {
        $parsed = $this->calendarDay($date, $this->timezone($tenant));

        if ($parsed === null) {
            return false;
        }

        $capacity = $this->capacityLimit(data_get(
            $tenant->settings,
            'scheduling.weekdays.'.$this->weekdayKey($parsed->dayOfWeek).'.capacity',
        ));

        if ($capacity === null) {
            return false;
        }

        return $this->occupied($tenant, $date) >= $capacity;
    }

    /** Pedidos não cancelados com esse dia. Sem dia não entra. A quantidade de itens não entra. */
    private function occupied(Tenant $tenant, string $date): int
    {
        return Order::query()
            ->forTenant($tenant->id)
            ->where('scheduled_on', $date)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->count();
    }

    /**
     * Contagem do mês visível, sem lock.
     *
     * @return array<string, int>
     */
    private function occupiedByDate(Tenant $tenant, string $start, string $end): array
    {
        $counts = [];

        $dates = Order::query()
            ->forTenant($tenant->id)
            ->whereNotNull('scheduled_on')
            ->whereBetween('scheduled_on', [$start, $end])
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->pluck('scheduled_on');

        foreach ($dates as $scheduledOn) {
            $key = $scheduledOn instanceof Carbon
                ? $scheduledOn->toDateString()
                : substr((string) $scheduledOn, 0, 10);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Item exatamente igual a um de `weekdays.{dia}.options`. O rótulo sai daqui.
     *
     * @return array{kind: string, start: string, end: string|null, label: string}|null
     */
    private function matchingOption(Tenant $tenant, string $weekday, mixed $sent): ?array
    {
        if (! is_array($sent)) {
            return null;
        }

        $options = data_get($tenant->settings, "scheduling.weekdays.{$weekday}.options");

        if (! is_array($options)) {
            return null;
        }

        $sentKind = $sent['kind'] ?? null;
        $sentStart = $sent['start'] ?? null;
        $sentEnd = array_key_exists('end', $sent) ? $sent['end'] : null;

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $kind = $option['kind'] ?? null;
            $start = $option['start'] ?? null;
            $end = array_key_exists('end', $option) ? $option['end'] : null;

            if (($kind !== 'time' && $kind !== 'range') || ! is_string($start)) {
                continue;
            }

            if ($end !== null && ! is_string($end)) {
                continue;
            }

            if ($sentKind !== $kind || $sentStart !== $start || $sentEnd !== $end) {
                continue;
            }

            return [
                'kind' => $kind,
                'start' => $start,
                'end' => $end,
                'label' => $kind === 'range' && is_string($end) ? $start."\u{2013}".$end : $start,
            ];
        }

        return null;
    }

    private function timezone(Tenant $tenant): string
    {
        $timezone = data_get($tenant->settings, 'business_hours.timezone');

        if (is_string($timezone) && self::isKnownTimezone($timezone)) {
            return $timezone;
        }

        return self::DEFAULT_TIMEZONE;
    }

    /** A mesma lista da regra `timezone` do horário da loja, inclusive nomes antigos. */
    private static function isKnownTimezone(string $timezone): bool
    {
        static $known = null;

        if ($known === null) {
            $known = array_flip(timezone_identifiers_list(DateTimeZone::ALL_WITH_BC));
        }

        return isset($known[$timezone]);
    }
}

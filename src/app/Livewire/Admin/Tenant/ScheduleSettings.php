<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class ScheduleSettings extends Component
{
    use AuthorizesOnHydrate;

    /** @var list<string> */
    private const WEEKDAY_KEYS = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];

    /** Rótulo na tela, de segunda a domingo. A chave gravada continua `sunday` … `saturday`. */
    private const DAY_LABELS = [
        'monday' => 'Segunda-feira',
        'tuesday' => 'Terça-feira',
        'wednesday' => 'Quarta-feira',
        'thursday' => 'Quinta-feira',
        'friday' => 'Sexta-feira',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public Tenant $tenant;

    public bool $enabled = false;

    public ?int $min_days = 0;

    public ?int $max_days = 30;

    /**
     * `capacity` vazia grava null. `"0"` grava 0. É string para o vazio não virar 0.
     *
     * @var array<string, mixed>
     */
    public array $weekdays = [];

    /** @var array<int, mixed> */
    public array $off_days = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $this->fillFrom($tenant);
    }

    public function addOption(string $day): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if (! $this->knownDay($day)) {
            return;
        }

        $this->ensureWeekdays();

        $this->weekdays[$day]['options'][] = $this->blankOption();
    }

    public function removeOption(string $day, int $index): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $options = $this->optionsOf($day);

        if (! array_key_exists($index, $options)) {
            return;
        }

        unset($options[$index]);
        $this->weekdays[$day]['options'] = array_values($options);
    }

    public function moveOptionUp(string $day, int $index): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->swapOption($day, $index, $index - 1);
    }

    public function moveOptionDown(string $day, int $index): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->swapOption($day, $index, $index + 1);
    }

    public function addOffDay(string $date): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $date = trim($date);

        if (! $this->isCalendarDate($date) || in_array($date, $this->off_days, true)) {
            return;
        }

        $this->off_days[] = $date;
    }

    public function removeOffDay(string $date): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->off_days = array_values(array_filter(
            $this->off_days,
            fn ($day) => $day !== $date,
        ));
    }

    public function updatedWeekdays(mixed $value, ?string $key = null): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if ($key === null) {
            $this->ensureWeekdays();

            return;
        }

        if ($value !== 'time' || ! str_ends_with($key, '.kind')) {
            return;
        }

        data_set($this->weekdays, substr($key, 0, -strlen('kind')).'end', '');
    }

    protected function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'min_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_days' => ['required', 'integer', 'min:0', 'max:365'],
            'off_days' => ['array'],
            'weekdays' => ['array'],
            'weekdays.*.enabled' => ['boolean'],
            'weekdays.*.capacity' => ['nullable', 'regex:/^(0|[1-9]\d{0,5})$/'],
            'weekdays.*.options' => ['array'],
            'weekdays.*.options.*.kind' => ['required', 'in:time,range'],
            'weekdays.*.options.*.start' => ['required', 'regex:'.self::TIME_PATTERN],
            'weekdays.*.options.*.end' => ['nullable', 'regex:'.self::TIME_PATTERN],
        ];
    }

    protected function messages(): array
    {
        return [
            'min_days.required' => 'Informe a antecedência.',
            'min_days.integer' => 'A antecedência é um inteiro de 0 a 365.',
            'min_days.min' => 'A antecedência é um inteiro de 0 a 365.',
            'min_days.max' => 'A antecedência é um inteiro de 0 a 365.',
            'max_days.required' => 'Informe o máximo de dias.',
            'max_days.integer' => 'O máximo é um inteiro de 0 a 365.',
            'max_days.min' => 'O máximo é um inteiro de 0 a 365.',
            'max_days.max' => 'O máximo é um inteiro de 0 a 365.',
            'weekdays.*.capacity.regex' => 'A capacidade fica em branco ou é um inteiro a partir de 0.',
            'weekdays.*.options.*.start.required' => 'Informe o horário.',
            'weekdays.*.options.*.start.regex' => 'Use uma hora de 00:00 a 23:59.',
            'weekdays.*.options.*.end.regex' => 'Use uma hora de 00:00 a 23:59.',
            'weekdays.*.options.*.kind.in' => 'Escolha horário ou intervalo.',
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->ensureWeekdays();
        $this->trimFields();

        $this->validate();

        if ($this->optionsAreInvalid()) {
            return;
        }

        $this->tenant->refresh();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'scheduling', $this->schedulingBlock());

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Agendamento salvo com sucesso!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.schedule-settings', [
            'days' => self::DAY_LABELS,
        ]);
    }

    private function fillFrom(Tenant $tenant): void
    {
        $block = data_get($tenant->settings, 'scheduling', []);

        if (! is_array($block)) {
            $block = [];
        }

        $this->enabled = ($block['enabled'] ?? false) === true;
        $this->min_days = $this->formInt($block['min_days'] ?? null, 0);
        $this->max_days = $this->formInt($block['max_days'] ?? null, 30);
        $this->off_days = $this->offDaysForForm($block['off_days'] ?? []);

        $stored = is_array($block['weekdays'] ?? null) ? $block['weekdays'] : [];
        $weekdays = [];

        foreach (self::WEEKDAY_KEYS as $day) {
            $current = $stored[$day] ?? null;
            $weekdays[$day] = is_array($current) ? $this->dayFromStored($current) : $this->blankDay();
        }

        $this->weekdays = $weekdays;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array{enabled: bool, capacity: string, options: list<array<string, mixed>>}
     */
    private function dayFromStored(array $current): array
    {
        return [
            'enabled' => ($current['enabled'] ?? true) !== false,
            'capacity' => $this->capacityForForm($current['capacity'] ?? null),
            'options' => $this->optionsForForm($current['options'] ?? []),
        ];
    }

    /** @return array{enabled: bool, capacity: string, options: list<array<string, mixed>>} */
    private function blankDay(): array
    {
        return [
            'enabled' => true,
            'capacity' => '',
            'options' => [],
        ];
    }

    /** @return array{uid: string, kind: string, start: string, end: string} */
    private function blankOption(): array
    {
        return [
            'uid' => (string) Str::uuid(),
            'kind' => 'time',
            'start' => '',
            'end' => '',
        ];
    }

    /** Dia ausente no save volta ao inicial: marcado, sem horário e sem limite. */
    private function ensureWeekdays(): void
    {
        foreach (self::WEEKDAY_KEYS as $day) {
            if (! isset($this->weekdays[$day]) || ! is_array($this->weekdays[$day])) {
                $this->weekdays[$day] = $this->blankDay();
            }
        }

        $ordered = [];

        foreach (self::WEEKDAY_KEYS as $day) {
            $ordered[$day] = $this->weekdays[$day];
        }

        $this->weekdays = $ordered;
    }

    private function trimFields(): void
    {
        foreach (self::WEEKDAY_KEYS as $day) {
            $row = $this->weekdays[$day];

            if (! is_array($row)) {
                continue;
            }

            $capacity = $row['capacity'] ?? '';

            if (is_string($capacity)) {
                $capacity = trim($capacity);
            } elseif (is_int($capacity)) {
                $capacity = (string) $capacity;
            }

            $this->weekdays[$day]['capacity'] = $capacity;

            $options = $row['options'] ?? null;

            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $index => $option) {
                if (! is_array($option)) {
                    continue;
                }

                $this->weekdays[$day]['options'][$index]['start'] = $this->clock($option['start'] ?? '');
                $this->weekdays[$day]['options'][$index]['end'] = $this->clock($option['end'] ?? '');

                if (! is_string($option['kind'] ?? null)) {
                    $this->weekdays[$day]['options'][$index]['kind'] = '';
                }
            }
        }
    }

    private function optionsAreInvalid(): bool
    {
        $invalid = false;

        foreach (self::WEEKDAY_KEYS as $day) {
            $options = $this->weekdays[$day]['options'] ?? [];

            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $index => $option) {
                if (! is_array($option)) {
                    continue;
                }

                $kind = $option['kind'] ?? '';
                $start = (string) ($option['start'] ?? '');
                $end = (string) ($option['end'] ?? '');
                $key = "weekdays.{$day}.options.{$index}.end";

                if ($kind === 'time') {
                    if ($end !== '') {
                        $this->addError($key, 'Horário simples não tem fim.');
                        $invalid = true;
                    }

                    continue;
                }

                if ($kind !== 'range') {
                    continue;
                }

                if ($end === '') {
                    $this->addError($key, 'Informe o fim do intervalo.');
                    $invalid = true;

                    continue;
                }

                if ($end <= $start) {
                    $this->addError($key, 'O fim do intervalo precisa ser depois do começo.');
                    $invalid = true;
                }
            }
        }

        return $invalid;
    }

    /** @return array<string, mixed> */
    private function schedulingBlock(): array
    {
        $weekdays = [];

        foreach (self::WEEKDAY_KEYS as $day) {
            $row = is_array($this->weekdays[$day] ?? null) ? $this->weekdays[$day] : $this->blankDay();

            $weekdays[$day] = [
                'enabled' => ($row['enabled'] ?? true) !== false,
                'capacity' => $this->capacityForStorage($row['capacity'] ?? ''),
                'options' => $this->optionsForStorage(is_array($row['options'] ?? null) ? $row['options'] : []),
            ];
        }

        return [
            'enabled' => $this->enabled === true,
            'min_days' => (int) $this->min_days,
            'max_days' => (int) $this->max_days,
            'weekdays' => $weekdays,
            'off_days' => $this->offDaysForStorage(),
        ];
    }

    /**
     * @param  array<int, mixed>  $options
     * @return list<array{kind: string, start: string, end: string|null}>
     */
    private function optionsForStorage(array $options): array
    {
        $stored = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $kind = $option['kind'] ?? '';
            $end = $option['end'] ?? '';

            $stored[] = [
                'kind' => is_string($kind) ? $kind : '',
                'start' => (string) ($option['start'] ?? ''),
                'end' => $kind === 'range' ? (string) $end : null,
            ];
        }

        return $stored;
    }

    /** @return list<string> */
    private function offDaysForStorage(): array
    {
        $days = [];

        foreach ($this->off_days as $date) {
            if (! is_string($date) || ! $this->isCalendarDate($date) || in_array($date, $days, true)) {
                continue;
            }

            $days[] = $date;
        }

        return $days;
    }

    /** @return list<array<string, mixed>> */
    private function optionsForForm(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $list = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $end = $option['end'] ?? '';

            $list[] = [
                'uid' => (string) Str::uuid(),
                'kind' => is_string($option['kind'] ?? null) ? $option['kind'] : '',
                'start' => is_string($option['start'] ?? null) ? $option['start'] : '',
                'end' => is_string($end) ? $end : '',
            ];
        }

        return $list;
    }

    /** @return list<string> */
    private function offDaysForForm(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $list = [];

        foreach ($days as $day) {
            if (! is_string($day) || ! $this->isCalendarDate($day) || in_array($day, $list, true)) {
                continue;
            }

            $list[] = $day;
        }

        return $list;
    }

    private function capacityForForm(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    private function capacityForStorage(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function formInt(mixed $value, int $default): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }

        return $default;
    }

    private function clock(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        if (preg_match('/^(\d{2}:\d{2}):00$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    private function isCalendarDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return false;
        }

        return $parsed instanceof Carbon && $parsed->format('Y-m-d') === $date;
    }

    private function knownDay(string $day): bool
    {
        return in_array($day, self::WEEKDAY_KEYS, true);
    }

    /** @return array<mixed> */
    private function optionsOf(string $day): array
    {
        if (! $this->knownDay($day)) {
            return [];
        }

        $options = $this->weekdays[$day]['options'] ?? null;

        if (! is_array($options)) {
            $this->weekdays[$day]['options'] = [];

            return [];
        }

        return $options;
    }

    private function swapOption(string $day, int $from, int $to): void
    {
        $options = $this->optionsOf($day);

        if (! array_key_exists($from, $options) || ! array_key_exists($to, $options)) {
            return;
        }

        $item = $options[$from];
        $options[$from] = $options[$to];
        $options[$to] = $item;
        $this->weekdays[$day]['options'] = array_values($options);
    }
}

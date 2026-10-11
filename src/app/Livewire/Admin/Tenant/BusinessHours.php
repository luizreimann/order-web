<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class BusinessHours extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public bool $enabled = false;

    public string $timezone = 'America/Sao_Paulo';

    public bool $auto_close = true;

    public bool $paused = false;

    public array $schedule = [];

    protected array $days = [
        'monday' => 'Segunda-feira',
        'tuesday' => 'Terça-feira',
        'wednesday' => 'Quarta-feira',
        'thursday' => 'Quinta-feira',
        'friday' => 'Sexta-feira',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $hours = data_get($tenant->settings, 'business_hours', []);

        $this->enabled = data_get($hours, 'enabled', false);
        $this->timezone = data_get($hours, 'timezone', 'America/Sao_Paulo');
        $this->auto_close = data_get($hours, 'auto_close', true);
        $this->paused = data_get($hours, 'paused', false);

        foreach ($this->days as $day => $label) {
            $this->schedule[$day] = data_get($hours, "schedule.{$day}", [
                'open' => '08:00',
                'close' => '18:00',
                'closed' => false,
            ]);
        }
    }

    protected function rules(): array
    {
        $rules = [
            'enabled' => ['boolean'],
            'timezone' => ['required', 'string', 'timezone'],
            'auto_close' => ['boolean'],
            'paused' => ['boolean'],
        ];

        foreach (array_keys($this->days) as $day) {
            $rules["schedule.{$day}.open"] = ['nullable', 'date_format:H:i'];
            $rules["schedule.{$day}.close"] = ['nullable', 'date_format:H:i'];
            $rules["schedule.{$day}.closed"] = ['boolean'];
        }

        return $rules;
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'business_hours', [
            'enabled' => $this->enabled,
            'timezone' => $this->timezone,
            'auto_close' => $this->auto_close,
            'paused' => $this->paused,
            'schedule' => $this->schedule,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Horários salvos com sucesso!');
    }

    public function togglePause(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->paused = ! $this->paused;
        $this->save();
    }

    public function getDaysProperty(): array
    {
        return $this->days;
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.business-hours');
    }
}

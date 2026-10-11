<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class DeliverySettings extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public float $minimum_order = 0;

    public float $default_fee = 0;

    public ?int $estimated_time_minutes = null;

    public float $free_delivery_above = 0;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $delivery = data_get($tenant->settings, 'delivery', []);

        $this->minimum_order = data_get($delivery, 'minimum_order_cents', 0) / 100;
        $this->default_fee = data_get($delivery, 'default_fee_cents', 0) / 100;
        $this->estimated_time_minutes = data_get($delivery, 'estimated_time_minutes');
        $this->free_delivery_above = data_get($delivery, 'free_delivery_above_cents', 0) / 100;
    }

    protected function rules(): array
    {
        return [
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'default_fee' => ['nullable', 'numeric', 'min:0'],
            'estimated_time_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'free_delivery_above' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'delivery', [
            'minimum_order_cents' => (int) ($this->minimum_order * 100),
            'default_fee_cents' => (int) ($this->default_fee * 100),
            'estimated_time_minutes' => $this->estimated_time_minutes,
            'free_delivery_above_cents' => (int) ($this->free_delivery_above * 100),
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Configurações de entrega salvas com sucesso!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.delivery-settings');
    }
}

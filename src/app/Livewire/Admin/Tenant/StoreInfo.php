<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class StoreInfo extends Component
{
    public Tenant $tenant;

    public string $short_description = '';
    public string $full_description = '';
    public bool $show_about = true;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $store = data_get($tenant->settings, 'store', []);

        $this->short_description = data_get($store, 'short_description', '');
        $this->full_description = data_get($store, 'full_description', '');
        $this->show_about = data_get($store, 'show_about', true);
    }

    protected function rules(): array
    {
        return [
            'short_description' => ['nullable', 'string', 'max:160'],
            'full_description' => ['nullable', 'string', 'max:2000'],
            'show_about' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'store', [
            'short_description' => $this->short_description,
            'full_description' => $this->full_description,
            'show_about' => $this->show_about,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Informações da loja salvas com sucesso!');
    }

    public function render()
    {
        return view('livewire.admin.tenant.store-info');
    }
}

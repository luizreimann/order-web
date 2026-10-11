<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class AddressSettings extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public ?string $street = null;

    public ?string $number = null;

    public ?string $complement = null;

    public ?string $neighborhood = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?string $zip_code = null;

    public ?string $google_maps_url = null;

    public bool $show_on_storefront = true;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $address = data_get($tenant->settings, 'address', []);

        $this->street = data_get($address, 'street');
        $this->number = data_get($address, 'number');
        $this->complement = data_get($address, 'complement');
        $this->neighborhood = data_get($address, 'neighborhood');
        $this->city = data_get($address, 'city');
        $this->state = data_get($address, 'state');
        $this->zip_code = data_get($address, 'zip_code');
        $this->google_maps_url = data_get($address, 'google_maps_url');
        $this->show_on_storefront = data_get($address, 'show_on_storefront', true);
    }

    protected function rules(): array
    {
        return [
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'google_maps_url' => ['nullable', 'url', 'max:500'],
            'show_on_storefront' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'address', [
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'google_maps_url' => $this->google_maps_url,
            'show_on_storefront' => $this->show_on_storefront,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Endereço salvo com sucesso!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.address-settings');
    }
}

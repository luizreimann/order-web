<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Support\Phone;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class ContactSettings extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public ?string $phone = null;

    public ?string $whatsapp = null;

    public ?string $email = null;

    public ?string $whatsapp_message = null;

    public bool $show_whatsapp_button = false;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $contact = data_get($tenant->settings, 'contact', []);

        $this->phone = Phone::display(data_get($contact, 'phone'));
        $this->whatsapp = Phone::display(data_get($contact, 'whatsapp'));
        $this->email = data_get($contact, 'email');
        $this->whatsapp_message = data_get($contact, 'whatsapp_message');
        $this->show_whatsapp_button = data_get($contact, 'show_whatsapp_button', false);
    }

    protected function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', new PhoneNumber, 'max:40'],
            'whatsapp' => ['nullable', 'string', new PhoneNumber, 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp_message' => ['nullable', 'string', 'max:500'],
            'show_whatsapp_button' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $phone = Phone::normalizeOrNull($this->phone);
        $whatsapp = Phone::normalizeOrNull($this->whatsapp);

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'contact', [
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'email' => $this->email,
            'whatsapp_message' => $this->whatsapp_message,
            'show_whatsapp_button' => $this->show_whatsapp_button,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->phone = Phone::display($phone);
        $this->whatsapp = Phone::display($whatsapp);

        session()->flash('status', 'Informações de contato salvas com sucesso!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.contact-settings');
    }
}

<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class ContactSettings extends Component
{
    public Tenant $tenant;

    public ?string $phone = null;
    public ?string $whatsapp = null;
    public ?string $email = null;
    public ?string $whatsapp_message = null;
    public bool $show_whatsapp_button = false;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $contact = data_get($tenant->settings, 'contact', []);

        $this->phone = data_get($contact, 'phone');
        $this->whatsapp = data_get($contact, 'whatsapp');
        $this->email = data_get($contact, 'email');
        $this->whatsapp_message = data_get($contact, 'whatsapp_message');
        $this->show_whatsapp_button = data_get($contact, 'show_whatsapp_button', false);
    }

    protected function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'regex:/^55\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp_message' => ['nullable', 'string', 'max:500'],
            'show_whatsapp_button' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'whatsapp.regex' => 'WhatsApp deve estar no formato: 55 + DDD + número (ex: 5511999999999)',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'contact', [
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'whatsapp_message' => $this->whatsapp_message,
            'show_whatsapp_button' => $this->show_whatsapp_button,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Informações de contato salvas com sucesso!');
    }

    public function render()
    {
        return view('livewire.admin.tenant.contact-settings');
    }
}

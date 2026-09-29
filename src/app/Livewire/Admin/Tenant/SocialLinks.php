<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class SocialLinks extends Component
{
    public Tenant $tenant;

    public ?string $instagram = null;

    public ?string $facebook = null;

    public ?string $tiktok = null;

    public ?string $youtube = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $social = data_get($tenant->settings, 'social', []);

        $this->instagram = data_get($social, 'instagram');
        $this->facebook = data_get($social, 'facebook');
        $this->tiktok = data_get($social, 'tiktok');
        $this->youtube = data_get($social, 'youtube');
    }

    protected function rules(): array
    {
        return [
            'instagram' => ['nullable', 'url', 'max:255'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'tiktok' => ['nullable', 'url', 'max:255'],
            'youtube' => ['nullable', 'url', 'max:255'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'social', [
            'instagram' => $this->instagram ?: null,
            'facebook' => $this->facebook ?: null,
            'tiktok' => $this->tiktok ?: null,
            'youtube' => $this->youtube ?: null,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Redes sociais salvas com sucesso!');
    }

    public function render()
    {
        return view('livewire.admin.tenant.social-links');
    }
}

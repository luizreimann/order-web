<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Settings extends Component
{
    public Tenant $tenant;

    public string $slug = '';
    public string $slugPreview = '';

    protected array $reserved = [
        'admin', 'login', 'logout', 'api', 'assets', 'storage',
        'css', 'js', 'img', 'images', 'vendor',
        'sitemap', 'robots', 'health', 'status',
    ];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->slug = $tenant->slug;
        $this->updatePreview();
    }

    public function updatedSlug(): void
    {
        // normaliza em tempo real: minúsculo, trim, espaços -> hífen
        $normalized = mb_strtolower(trim($this->slug));
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? $normalized;

        $this->slug = $normalized;
        $this->updatePreview();
    }

    private function updatePreview(): void
    {
        $this->slugPreview = url('/' . ltrim($this->slug ?: $this->tenant->slug, '/'));
    }

    protected function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:50',

                // apenas letras/números e hífen no meio
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',

                // não pode ser reservado
                function ($attribute, $value, $fail) {
                    if (in_array($value, $this->reserved, true)) {
                        $fail('Este slug é reservado.');
                    }
                },

                // único entre tenants (ignorando o atual)
                Rule::unique('tenants', 'slug')->ignore($this->tenant->id),
            ],
        ];
    }

    public function save(): void
    {
        $this->validate();

        if ($this->slug === $this->tenant->slug) {
            session()->flash('status', 'Nada para atualizar.');
            return;
        }

        $old = $this->tenant->slug;

        $this->tenant->slug = $this->slug;
        $this->tenant->save();

        session()->flash('status', "Slug atualizado de /{$old} para /{$this->tenant->slug}.");

        // IMPORTANTE: muda a URL base do tenant, então redireciona
        $this->redirectRoute('tenant.admin.settings', ['tenant' => $this->tenant->slug], navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.tenant.settings')
            ->layout('layouts.tenant-admin');
    }
}
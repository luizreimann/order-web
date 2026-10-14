<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use App\Support\ImageUpload;
use App\Support\TenantTheme;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class Customization extends Component
{
    use AuthorizesOnHydrate;
    use WithFileUploads;

    public Tenant $tenant;

    public string $name = '';

    public string $primary_color = '#0d6efd';

    public string $secondary_color = '#6c757d';

    public string $font_family = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $banner = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $favicon = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $this->name = $tenant->name;
        // valores legados/inválidos no banco nunca voltam ao formulário nem ao preview (E5.1)
        $this->primary_color = TenantTheme::primary($tenant->primary_color);
        $this->secondary_color = TenantTheme::secondary($tenant->secondary_color);

        $this->font_family = TenantTheme::fontStack(data_get($tenant->settings, 'theme.font_family'));
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'primary_color' => ['required', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'secondary_color' => ['required', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'font_family' => ['required', 'string', Rule::in(TenantTheme::fontStacks())],

            // política única em App\Support\ImageUpload (conteúdo real, sem SVG, dimensões máximas)
            'logo' => ImageUpload::rules(2048), // 2MB
            'banner' => ImageUpload::rules(5120), // 5MB
            'favicon' => ImageUpload::rules(512, favicon: true), // 512KB, ico/png
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $this->tenant->name = $this->name;
        $this->tenant->primary_color = $this->primary_color;
        $this->tenant->secondary_color = $this->secondary_color;

        // settings.theme.font_family
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'theme.font_family', $this->font_family);
        $this->tenant->settings = $settings;

        $tenantId = $this->tenant->id;
        $directory = "tenants/{$tenantId}";

        // logo upload (opcional)
        if ($this->logo) {
            $this->tenant->logo_path = ImageUpload::store(
                $this->logo, $tenantId, $directory, $this->tenant->logo_path
            );
        }

        // banner upload (opcional)
        if ($this->banner) {
            data_set($settings, 'theme.banner_path', ImageUpload::store(
                $this->banner, $tenantId, $directory, data_get($settings, 'theme.banner_path')
            ));
            $this->tenant->settings = $settings;
        }

        // favicon upload (opcional)
        if ($this->favicon) {
            data_set($settings, 'theme.favicon_path', ImageUpload::store(
                $this->favicon, $tenantId, $directory, data_get($settings, 'theme.favicon_path'), favicon: true
            ));
            $this->tenant->settings = $settings;
        }

        $this->tenant->save();

        session()->flash('status', 'Personalização salva com sucesso!');
        $this->dispatch('tenant-theme-saved');
    }

    public function removeLogo(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if ($this->tenant->logo_path && Storage::disk('public')->exists($this->tenant->logo_path)) {
            Storage::disk('public')->delete($this->tenant->logo_path);
        }

        $this->tenant->logo_path = null;
        $this->tenant->save();

        $this->logo = null;
        session()->flash('status', 'Logo removida.');
    }

    public function getLogoUrlProperty(): ?string
    {
        // só pré-visualiza o que o Livewire sabe renderizar (ex.: .ico e arquivos rejeitados
        // pela validação não são "previewable" e `temporaryUrl()` lançaria 500)
        if ($this->logo && $this->logo->isPreviewable()) {
            return $this->logo->temporaryUrl();
        }

        if ($this->tenant->logo_path) {
            return Storage::url($this->tenant->logo_path);
        }

        return null;
    }

    public function getBannerUrlProperty(): ?string
    {
        // só pré-visualiza o que o Livewire sabe renderizar (ex.: .ico e arquivos rejeitados
        // pela validação não são "previewable" e `temporaryUrl()` lançaria 500)
        if ($this->banner && $this->banner->isPreviewable()) {
            return $this->banner->temporaryUrl();
        }

        $path = data_get($this->tenant->settings, 'theme.banner_path');

        if ($path) {
            return Storage::url($path);
        }

        return null;
    }

    public function getFaviconUrlProperty(): ?string
    {
        // só pré-visualiza o que o Livewire sabe renderizar (ex.: .ico e arquivos rejeitados
        // pela validação não são "previewable" e `temporaryUrl()` lançaria 500)
        if ($this->favicon && $this->favicon->isPreviewable()) {
            return $this->favicon->temporaryUrl();
        }

        $path = data_get($this->tenant->settings, 'theme.favicon_path');

        if ($path) {
            return Storage::url($path);
        }

        return null;
    }

    public function removeBanner(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $settings = $this->tenant->settings ?? [];
        $bannerPath = data_get($settings, 'theme.banner_path');

        if ($bannerPath && Storage::disk('public')->exists($bannerPath)) {
            Storage::disk('public')->delete($bannerPath);
        }

        data_set($settings, 'theme.banner_path', null);
        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->banner = null;
        session()->flash('status', 'Banner removido.');
    }

    public function removeFavicon(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $settings = $this->tenant->settings ?? [];
        $faviconPath = data_get($settings, 'theme.favicon_path');

        if ($faviconPath && Storage::disk('public')->exists($faviconPath)) {
            Storage::disk('public')->delete($faviconPath);
        }

        data_set($settings, 'theme.favicon_path', null);
        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->favicon = null;
        session()->flash('status', 'Favicon removido.');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.customization', [
            'fontOptions' => TenantTheme::fontOptions(),
        ])->layout('layouts.tenant-admin');
    }
}

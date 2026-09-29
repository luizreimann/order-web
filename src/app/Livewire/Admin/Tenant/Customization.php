<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class Customization extends Component
{
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

        $this->name = $tenant->name;
        $this->primary_color = $tenant->primary_color ?: $this->primary_color;
        $this->secondary_color = $tenant->secondary_color ?: $this->secondary_color;

        $this->font_family = data_get($tenant->settings, 'theme.font_family')
            ?: $this->font_family;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'primary_color' => ['required', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'secondary_color' => ['required', 'regex:/^#([A-Fa-f0-9]{6})$/'],
            'font_family' => ['required', 'string', 'max:255'],

            'logo' => ['nullable', 'image', 'max:2048'], // 2MB
            'banner' => ['nullable', 'image', 'max:5120'], // 5MB
            'favicon' => ['nullable', 'image', 'mimes:ico,png', 'max:512'], // 512KB
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->tenant->name = $this->name;
        $this->tenant->primary_color = $this->primary_color;
        $this->tenant->secondary_color = $this->secondary_color;

        // settings.theme.font_family
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'theme.font_family', $this->font_family);
        $this->tenant->settings = $settings;

        // logo upload (opcional)
        if ($this->logo) {
            if ($this->tenant->logo_path && Storage::disk('public')->exists($this->tenant->logo_path)) {
                Storage::disk('public')->delete($this->tenant->logo_path);
            }

            $ext = $this->logo->getClientOriginalExtension() ?: 'png';
            $path = "tenants/{$this->tenant->id}/logo.{$ext}";

            $this->logo->storePubliclyAs("tenants/{$this->tenant->id}", "logo.{$ext}", 'public');

            $this->tenant->logo_path = $path;
        }

        // banner upload (opcional)
        if ($this->banner) {
            $bannerPath = data_get($settings, 'theme.banner_path');
            if ($bannerPath && Storage::disk('public')->exists($bannerPath)) {
                Storage::disk('public')->delete($bannerPath);
            }

            $ext = $this->banner->getClientOriginalExtension() ?: 'jpg';
            $path = "tenants/{$this->tenant->id}/banner.{$ext}";

            $this->banner->storePubliclyAs("tenants/{$this->tenant->id}", "banner.{$ext}", 'public');

            data_set($settings, 'theme.banner_path', $path);
            $this->tenant->settings = $settings;
        }

        // favicon upload (opcional)
        if ($this->favicon) {
            $faviconPath = data_get($settings, 'theme.favicon_path');
            if ($faviconPath && Storage::disk('public')->exists($faviconPath)) {
                Storage::disk('public')->delete($faviconPath);
            }

            $ext = $this->favicon->getClientOriginalExtension() ?: 'png';
            $path = "tenants/{$this->tenant->id}/favicon.{$ext}";

            $this->favicon->storePubliclyAs("tenants/{$this->tenant->id}", "favicon.{$ext}", 'public');

            data_set($settings, 'theme.favicon_path', $path);
            $this->tenant->settings = $settings;
        }

        $this->tenant->save();

        session()->flash('status', 'Personalização salva com sucesso!');
        $this->dispatch('tenant-theme-saved');
    }

    public function removeLogo(): void
    {
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
        if ($this->logo) {
            return $this->logo->temporaryUrl();
        }

        if ($this->tenant->logo_path) {
            return Storage::url($this->tenant->logo_path);
        }

        return null;
    }

    public function getBannerUrlProperty(): ?string
    {
        if ($this->banner) {
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
        if ($this->favicon) {
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

    public function render()
    {
        $fontOptions = [
            'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif' => 'System (padrão)',
            'Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif' => 'Inter',
            'Poppins, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif' => 'Poppins',
            'Montserrat, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif' => 'Montserrat',
        ];

        return view('livewire.admin.tenant.customization', [
            'fontOptions' => $fontOptions,
        ])->layout('layouts.tenant-admin');
    }
}

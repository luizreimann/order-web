<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

#[Layout('layouts.tenant-admin')]
class SeoSettings extends Component
{
    use WithFileUploads;

    public Tenant $tenant;

    public ?string $meta_title = null;
    public ?string $meta_description = null;
    public ?string $google_analytics_id = null;

    public $og_image = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $seo = data_get($tenant->settings, 'seo', []);

        $this->meta_title = data_get($seo, 'meta_title');
        $this->meta_description = data_get($seo, 'meta_description');
        $this->google_analytics_id = data_get($seo, 'google_analytics_id');
    }

    protected function rules(): array
    {
        return [
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'google_analytics_id' => ['nullable', 'string', 'max:50'],
            'og_image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $settings = $this->tenant->settings ?? [];

        $ogImagePath = data_get($settings, 'seo.og_image_path');

        if ($this->og_image) {
            if ($ogImagePath && Storage::disk('public')->exists($ogImagePath)) {
                Storage::disk('public')->delete($ogImagePath);
            }

            $ext = $this->og_image->getClientOriginalExtension() ?: 'jpg';
            $path = "tenants/{$this->tenant->id}/og-image.{$ext}";

            $this->og_image->storePubliclyAs("tenants/{$this->tenant->id}", "og-image.{$ext}", 'public');

            $ogImagePath = $path;
        }

        data_set($settings, 'seo', [
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'google_analytics_id' => $this->google_analytics_id,
            'og_image_path' => $ogImagePath,
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Configurações de SEO salvas com sucesso!');
    }

    public function removeOgImage(): void
    {
        $settings = $this->tenant->settings ?? [];
        $ogImagePath = data_get($settings, 'seo.og_image_path');

        if ($ogImagePath && Storage::disk('public')->exists($ogImagePath)) {
            Storage::disk('public')->delete($ogImagePath);
        }

        data_set($settings, 'seo.og_image_path', null);
        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->og_image = null;
        session()->flash('status', 'Imagem OG removida.');
    }

    public function getOgImageUrlProperty(): ?string
    {
        if ($this->og_image) {
            return $this->og_image->temporaryUrl();
        }

        $path = data_get($this->tenant->settings, 'seo.og_image_path');

        if ($path) {
            return Storage::url($path);
        }

        return null;
    }

    public function render()
    {
        return view('livewire.admin.tenant.seo-settings');
    }
}

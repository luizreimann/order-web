<?php

namespace App\Livewire\Tenant;

use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Support\TenantHelper;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Home extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function render()
    {
        $showcases = Showcase::query()
            ->forTenant($this->tenant->id)
            ->active()
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        $showcasesForView = $showcases->map(function (Showcase $showcase) {
            $products = $showcase->getProducts();

            return [
                'id' => $showcase->id,
                'name' => $showcase->name,
                'type' => $showcase->type,
                'display_mode' => $showcase->display_mode ?? 'list',
                'category_slug' => $showcase->category?->slug,
                'products' => $products->map(function (Product $p) {
                    $imagePath = data_get($p->meta, 'image_path');

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'slug' => $p->slug,
                        'price_cents' => (int) $p->price_cents,
                        'image_url' => $imagePath ? Storage::url($imagePath) : null,
                    ];
                })->all(),
            ];
        })->filter(fn($s) => count($s['products']) > 0)->values()->all();

        $bannerPath = data_get($this->tenant->settings, 'theme.banner_path');
        $bannerUrl = $bannerPath ? Storage::url($bannerPath) : null;

        $isOpen = TenantHelper::isOpen($this->tenant);
        $deliveryInfo = TenantHelper::getDeliveryInfo($this->tenant);

        return view('livewire.tenant.home', [
            'showcases' => $showcasesForView,
            'bannerUrl' => $bannerUrl,
            'isOpen' => $isOpen,
            'deliveryInfo' => $deliveryInfo,
        ]);
    }
}
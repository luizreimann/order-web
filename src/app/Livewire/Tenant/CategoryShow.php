<?php

namespace App\Livewire\Tenant;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CategoryShow extends Component
{
    public Tenant $tenant;

    public Category $category;

    public function mount(Tenant $tenant, string $categorySlug): void
    {
        $this->tenant = $tenant;

        $this->category = Category::query()
            ->forTenant($tenant->id)
            ->where('slug', $categorySlug)
            ->active()
            ->firstOrFail();
    }

    public function render()
    {
        $products = Product::query()
            ->forTenant($this->tenant->id)
            ->active()
            ->whereHas('categories', fn ($q) => $q->where('categories.id', $this->category->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $productsForView = $products->map(function (Product $p) {
            $imagePath = data_get($p->meta, 'image_path');

            return [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'price_cents' => (int) $p->price_cents,
                'image_url' => $imagePath ? Storage::url($imagePath) : null,
            ];
        })->all();

        return view('livewire.tenant.category-show', [
            'products' => $productsForView,
        ]);
    }
}

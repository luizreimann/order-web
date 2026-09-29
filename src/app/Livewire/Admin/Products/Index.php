<?php

namespace App\Livewire\Admin\Products;

use App\Models\Product;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    public Tenant $tenant;

    public string $search = '';

    public string $status = 'all'; // all|active|inactive

    public string $sort = 'newest'; // newest|name|price

    public ?string $category = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // Captura o filtro de categoria da query string
        if (request()->has('category')) {
            $this->category = request()->get('category');
        }
    }

    public function toggleActive(string $productId): void
    {
        $product = Product::query()
            ->forTenant($this->tenant->id)
            ->where('id', $productId)
            ->firstOrFail();

        $product->is_active = ! $product->is_active;
        $product->save();
    }

    public function delete(string $productId): void
    {
        $product = Product::query()
            ->forTenant($this->tenant->id)
            ->where('id', $productId)
            ->firstOrFail();

        $product->delete();
    }

    public function render()
    {
        $query = Product::query()->forTenant($this->tenant->id);

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('name', 'ilike', '%'.$this->search.'%')
                    ->orWhere('sku', 'ilike', '%'.$this->search.'%');
            });
        }

        if ($this->status === 'active') {
            $query->where('is_active', true);
        } elseif ($this->status === 'inactive') {
            $query->where('is_active', false);
        }

        // Filtro por categoria
        if ($this->category) {
            $query->whereHas('categories', function ($q) {
                $q->where('categories.id', $this->category);
            });
        }

        if ($this->sort === 'name') {
            $query->orderBy('name');
        } elseif ($this->sort === 'price') {
            $query->orderBy('price_cents');
        } else {
            $query->orderByDesc('created_at');
        }

        $products = $query
            ->with(['categories:id,name,tenant_id'])
            ->limit(200)
            ->get();

        // Buscar categorias para o filtro
        $categories = \App\Models\Category::query()
            ->forTenant($this->tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.products.index', [
            'products' => $products,
            'categories' => $categories,
        ])->layout('layouts.tenant-admin');
    }
}

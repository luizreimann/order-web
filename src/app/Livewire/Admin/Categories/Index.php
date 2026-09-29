<?php

namespace App\Livewire\Admin\Categories;

use App\Models\Category;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    public Tenant $tenant;

    public string $search = '';

    public string $status = 'all'; // all|active|inactive

    public string $sort = 'order';  // order|name|newest

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function toggleActive(string $categoryId): void
    {
        $cat = Category::query()
            ->forTenant($this->tenant->id)
            ->where('id', $categoryId)
            ->firstOrFail();

        $cat->is_active = ! $cat->is_active;
        $cat->save();
    }

    public function delete(string $categoryId): void
    {
        $cat = Category::query()
            ->forTenant($this->tenant->id)
            ->where('id', $categoryId)
            ->firstOrFail();

        $cat->delete();
    }

    public function render()
    {
        $q = Category::query()->forTenant($this->tenant->id);

        if ($this->search !== '') {
            $q->where(function ($x) {
                $x->where('name', 'ilike', '%'.$this->search.'%')
                    ->orWhere('slug', 'ilike', '%'.$this->search.'%');
            });
        }

        if ($this->status === 'active') {
            $q->where('is_active', true);
        }
        if ($this->status === 'inactive') {
            $q->where('is_active', false);
        }

        if ($this->sort === 'name') {
            $q->orderBy('name');
        } elseif ($this->sort === 'newest') {
            $q->orderByDesc('created_at');
        } else {
            $q->orderBy('sort_order')->orderBy('name');
        }

        $categories = $q->limit(300)
            ->withCount('products')
            ->get();

        return view('livewire.admin.categories.index', [
            'categories' => $categories,
        ])->layout('layouts.tenant-admin');
    }
}

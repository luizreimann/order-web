<?php

namespace App\Livewire\Admin\Showcases;

use App\Models\Showcase;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function toggleActive(string $showcaseId): void
    {
        $showcase = Showcase::query()
            ->forTenant($this->tenant->id)
            ->where('id', $showcaseId)
            ->firstOrFail();

        $showcase->is_active = ! $showcase->is_active;
        $showcase->save();
    }

    public function delete(string $showcaseId): void
    {
        $showcase = Showcase::query()
            ->forTenant($this->tenant->id)
            ->where('id', $showcaseId)
            ->firstOrFail();

        $showcase->delete();
    }

    public function updateOrder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            Showcase::query()
                ->forTenant($this->tenant->id)
                ->where('id', $id)
                ->update(['sort_order' => $index]);
        }
    }

    public function render()
    {
        $showcases = Showcase::query()
            ->forTenant($this->tenant->id)
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        $canCreate = $showcases->count() < Showcase::MAX_SHOWCASES_PER_TENANT;

        return view('livewire.admin.showcases.index', [
            'showcases' => $showcases,
            'canCreate' => $canCreate,
            'types' => Showcase::getTypes(),
        ])->layout('layouts.tenant-admin');
    }
}

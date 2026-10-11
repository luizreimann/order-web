<?php

namespace App\Livewire\Admin\Showcases;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Showcase;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();
    }

    public function toggleActive(string $showcaseId): void
    {
        $showcase = Showcase::query()
            ->forTenant($this->tenant->id)
            ->where('id', $showcaseId)
            ->firstOrFail();

        $this->authorize('update', $showcase);

        $showcase->is_active = ! $showcase->is_active;
        $showcase->save();
    }

    public function delete(string $showcaseId): void
    {
        $showcase = Showcase::query()
            ->forTenant($this->tenant->id)
            ->where('id', $showcaseId)
            ->firstOrFail();

        $this->authorize('delete', $showcase);

        $showcase->delete();
    }

    public function updateOrder(array $orderedIds): void
    {
        $showcases = Showcase::query()
            ->forTenant($this->tenant->id)
            ->whereIn('id', array_values($orderedIds))
            ->get()
            ->keyBy('id');

        foreach ($orderedIds as $index => $id) {
            $showcase = $showcases->get($id);

            if ($showcase === null) {
                continue;
            }

            $this->authorize('update', $showcase);

            $showcase->update(['sort_order' => $index]);
        }
    }

    protected function authorizeView(): void
    {
        $this->authorize('viewAny', [Showcase::class, $this->tenant]);
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

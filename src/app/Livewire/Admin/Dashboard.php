<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use Livewire\Component;

class Dashboard extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();
    }

    protected function authorizeView(): void
    {
        $this->authorize('view', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.dashboard')
            ->layout('layouts.tenant-admin');
    }
}

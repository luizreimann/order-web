<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use Livewire\Component;

class Dashboard extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function render()
    {
        return view('livewire.admin.dashboard')
            ->layout('layouts.tenant-admin');
    }
}
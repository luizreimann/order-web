<?php

namespace App\Livewire\Tenant\Account;

use App\Models\Customer;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public Tenant $tenant;
    public Customer $customer;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        
        $customer = request()->get('authenticated_customer');
        
        if (!$customer) {
            session()->flash('error', 'Você precisa fazer login para acessar esta página.');
            $this->redirect(route('tenant.home', ['tenant' => $tenant->slug]));
            return;
        }
        
        $this->customer = $customer;
    }

    public function render()
    {
        $recentOrders = $this->customer->orders()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('livewire.tenant.account.index', [
            'recentOrders' => $recentOrders,
        ]);
    }
}

<?php

namespace App\Livewire\Admin\Customers;

use App\Models\Customer;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    public Tenant $tenant;

    public string $search = '';
    public string $status = 'active'; // active|inactive|all
    public string $sort = 'name'; // name|newest

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function toggleActive(string $customerId): void
    {
        $c = Customer::query()
            ->forTenant($this->tenant->id)
            ->where('id', $customerId)
            ->firstOrFail();

        $c->is_active = !$c->is_active;
        $c->save();
    }

    public function delete(string $customerId): void
    {
        $c = Customer::query()
            ->forTenant($this->tenant->id)
            ->where('id', $customerId)
            ->firstOrFail();

        // MVP: delete direto (se quiser soft delete depois, a gente muda)
        $c->delete();
    }

    public function render()
    {
        $q = Customer::query()->forTenant($this->tenant->id);

        if ($this->search !== '') {
            $term = trim($this->search);
            $q->where(function ($x) use ($term) {
                $x->where('name', 'ilike', "%{$term}%")
                  ->orWhere('phone', 'ilike', "%{$term}%")
                  ->orWhere('email', 'ilike', "%{$term}%");
            });
        }

        if ($this->status === 'active') $q->where('is_active', true);
        if ($this->status === 'inactive') $q->where('is_active', false);

        if ($this->sort === 'newest') $q->orderByDesc('created_at');
        else $q->orderBy('name');

        $customers = $q->limit(300)->get();

        return view('livewire.admin.customers.index', [
            'customers' => $customers,
        ])->layout('layouts.tenant-admin');
    }
}
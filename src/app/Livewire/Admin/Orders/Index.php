<?php

namespace App\Livewire\Admin\Orders;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Order;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public string $search = '';

    public string $status = 'all'; // all|pending|confirmed|cancelled|done

    public string $payment = 'all'; // all|unpaid|paid|refunded

    public string $sort = 'newest'; // newest|oldest|total_desc

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();
    }

    protected function authorizeView(): void
    {
        $this->authorize('viewAny', [Order::class, $this->tenant]);
    }

    public function render()
    {
        $q = Order::query()->forTenant($this->tenant->id);

        if ($this->search !== '') {
            $term = trim($this->search);

            $q->where(function ($x) use ($term) {
                if (ctype_digit($term)) {
                    $x->orWhere('number', (int) $term);
                }

                $x->orWhere('customer_name', 'ilike', '%'.$term.'%')
                    ->orWhere('customer_phone', 'ilike', '%'.$term.'%');
            });
        }

        if ($this->status !== 'all') {
            $q->where('status', $this->status);
        }

        if ($this->payment !== 'all') {
            $q->where('payment_status', $this->payment);
        }

        if ($this->sort === 'oldest') {
            $q->orderBy('created_at');
        } elseif ($this->sort === 'total_desc') {
            $q->orderByDesc('total_cents')->orderByDesc('created_at');
        } else {
            $q->orderByDesc('created_at');
        }

        $orders = $q->limit(200)->get();

        return view('livewire.admin.orders.index', [
            'orders' => $orders,
        ])->layout('layouts.tenant-admin');
    }
}

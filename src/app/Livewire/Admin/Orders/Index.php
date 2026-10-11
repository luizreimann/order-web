<?php

namespace App\Livewire\Admin\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Order;
use App\Models\Tenant;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public string $search = '';

    public string $status = 'all'; // 'all' ou um valor de OrderStatus

    public string $payment = 'all'; // 'all' ou um valor de PaymentStatus

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

        // Valor fora do enum (ex.: querystring adulterada) é ignorado em vez de virar query.
        if ($status = OrderStatus::tryFrom($this->status)) {
            $q->where('status', $status->value);
        }

        if ($payment = PaymentStatus::tryFrom($this->payment)) {
            $q->where('payment_status', $payment->value);
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

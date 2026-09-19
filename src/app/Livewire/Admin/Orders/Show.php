<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\Tenant;
use Livewire\Component;

class Show extends Component
{
    public Tenant $tenant;
    public Order $order;

    public string $status = '';
    public string $payment_status = '';
    public ?string $payment_method = null;

    public function mount(Tenant $tenant, string $orderId): void
    {
        $this->tenant = $tenant;

        $this->order = Order::query()
            ->forTenant($tenant->id)
            ->with('items')
            ->where('id', $orderId)
            ->firstOrFail();

        $this->status = $this->order->status;
        $this->payment_status = $this->order->payment_status;
        $this->payment_method = $this->order->payment_method;
    }

    public function updateStatus(): void
    {
        $this->validate([
            'status' => ['required', 'in:pending,confirmed,done,cancelled'],
            'payment_status' => ['required', 'in:unpaid,paid,refunded'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $this->order->status = $this->status;
        $this->order->payment_status = $this->payment_status;
        $this->order->payment_method = $this->payment_method ?: null;
        $this->order->save();

        session()->flash('status', 'Pedido atualizado!');
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.tenant-admin');
    }
}
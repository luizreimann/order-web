<?php

namespace App\Livewire\Admin\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStockException;
use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesOnHydrate;

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

        $this->authorizeView();

        $this->status = $this->order->status->value;
        $this->payment_status = $this->order->payment_status->value;
        $this->payment_method = $this->order->payment_method;
    }

    public function updateStatus(OrderService $orders): void
    {
        $this->authorize('update', $this->order);

        $validated = $this->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            // Cancelar devolve o estoque (uma vez); reativar baixa de novo — ver OrderService::updateOrder.
            $orders->updateOrder(
                $this->order,
                OrderStatus::from($validated['status']),
                PaymentStatus::from($validated['payment_status']),
                $this->payment_method ?: null,
            );
        } catch (InsufficientStockException $e) {
            $this->addError('status', 'Não é possível reativar o pedido: '.$e->getMessage());

            return;
        }

        session()->flash('status', 'Pedido atualizado!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('view', $this->order);
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.tenant-admin');
    }
}

<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function createFromCart(
        Tenant $tenant,
        array $customerData,
        array $cart,
        string $paymentMethod,
        array $paymentMeta = []
    ): Order {
        return DB::transaction(function () use ($tenant, $customerData, $cart, $paymentMethod, $paymentMeta) {
            $customer = $this->resolveCustomer($tenant, $customerData);
            $lines = $this->resolveCartLines($tenant, $cart);
            $subtotalCents = $this->calculateSubtotal($lines);

            $order = Order::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => $paymentMethod,
                'customer_name' => $customerData['name'] ?? $customer->name,
                'customer_phone' => $customerData['phone'] ?? $customer->phone,
                'fulfillment_type' => 'pickup',
                'subtotal_cents' => $subtotalCents,
                'discount_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_cents' => $subtotalCents,
                'meta' => [
                    'payment' => $paymentMeta,
                ],
            ]);

            $this->createOrderItems($tenant, $order, $lines);

            return $order;
        });
    }

    public function resolveCustomer(Tenant $tenant, array $customerData): Customer
    {
        $name = trim((string) ($customerData['name'] ?? ''));
        $email = trim((string) ($customerData['email'] ?? ''));
        $phone = trim((string) ($customerData['phone'] ?? ''));

        $customer = null;

        if ($phone !== '') {
            $customer = Customer::query()
                ->forTenant($tenant->id)
                ->where('phone', $phone)
                ->first();
        }

        if (!$customer) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone ?: null,
                'is_active' => true,
                'meta' => [],
            ]);
        } else {
            if ($name !== '') $customer->name = $name;
            if ($email !== '') $customer->email = $email;
            if ($phone !== '') $customer->phone = $phone;
            $customer->save();
        }

        return $customer;
    }

    public function resolveCartLines(Tenant $tenant, array $cart): array
    {
        $lines = [];

        foreach ($cart as $line) {
            $productId = (string) ($line['product_id'] ?? '');
            $qty = (int) ($line['qty'] ?? 0);

            if (!$productId || $qty <= 0) continue;

            $product = Product::query()
                ->forTenant($tenant->id)
                ->where('id', $productId)
                ->first();

            if (!$product) continue;

            $unitCents = (int) ($product->price_cents ?? 0);

            $lines[] = [
                'product' => $product,
                'qty' => $qty,
                'unit_cents' => $unitCents,
                'total_cents' => $unitCents * $qty,
            ];
        }

        return $lines;
    }

    public function calculateSubtotal(array $lines): int
    {
        return (int) collect($lines)->sum('total_cents');
    }

    protected function createOrderItems(Tenant $tenant, Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];

            OrderItem::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $line['qty'],
                'unit_price_cents' => $line['unit_cents'],
                'total_cents' => $line['total_cents'],
                'notes' => null,
                'meta' => [],
            ]);
        }
    }
}

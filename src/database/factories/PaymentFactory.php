<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'order_id' => Order::factory(),
            'gateway' => 'mercadopago',
            'gateway_payment_id' => (string) fake()->unique()->randomNumber(9),
            'amount_cents' => fake()->numberBetween(1000, 50000),
            'status' => 'pending',
            'status_detail' => null,
            'meta' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'status_detail' => 'accredited',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_other_reason',
        ]);
    }

    public function forMercadoPago(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => 'mercadopago',
        ]);
    }
}

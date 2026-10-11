<?php

namespace Database\Factories;

use App\Enums\GatewayPaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
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
            'status' => GatewayPaymentStatus::PENDING->value,
            'status_detail' => null,
            'meta' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GatewayPaymentStatus::APPROVED->value,
            'status_detail' => 'accredited',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GatewayPaymentStatus::REJECTED->value,
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

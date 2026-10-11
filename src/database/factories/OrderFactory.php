<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1000, 50000);

        return [
            'tenant_id' => Tenant::factory(),
            'number' => null,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 'cash_on_delivery',
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'fulfillment_type' => 'delivery',
            'scheduled_for' => null,
            'notes' => null,
            'subtotal_cents' => $subtotal,
            'discount_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => $subtotal,
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'address_city' => fake()->city(),
            'address_state' => fake()->stateAbbr(),
            'address_postal_code' => fake()->postcode(),
            'meta' => [],
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatus::PAID,
        ]);
    }

    public function withMercadoPagoPayment(string $paymentId): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method' => 'mp_pix',
            'meta' => array_merge($attributes['meta'] ?? [], [
                'mercadopago' => [
                    'payment_id' => $paymentId,
                ],
            ]),
        ]);
    }
}

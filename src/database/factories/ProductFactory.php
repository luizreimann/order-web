<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => strtoupper(fake()->unique()->bothify('???-####')),
            'description' => fake()->paragraph(),
            'price_cents' => fake()->numberBetween(500, 15000),
            'compare_at_cents' => null,
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
            'min_days_notice' => 0,
            'requires_scheduling' => false,
            'meta' => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    public function withPrice(int $priceCents): static
    {
        return $this->state(fn (array $attributes) => [
            'price_cents' => $priceCents,
        ]);
    }
}

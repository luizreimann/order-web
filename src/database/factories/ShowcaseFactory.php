<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Showcase>
 */
class ShowcaseFactory extends Factory
{
    protected $model = Showcase::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => Showcase::TYPE_ALL,
            'category_id' => null,
            'max_items' => 8,
            'sort_order' => 0,
            'is_active' => true,
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
            'type' => Showcase::TYPE_FEATURED,
            'name' => 'Destaques',
            'slug' => 'destaques',
        ]);
    }

    public function bestSellers(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Showcase::TYPE_BEST_SELLERS,
            'name' => 'Mais Vendidos',
            'slug' => 'mais-vendidos',
        ]);
    }

    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Showcase::TYPE_RECENT,
            'name' => 'Novidades',
            'slug' => 'novidades',
        ]);
    }

    public function category(?Category $category = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Showcase::TYPE_CATEGORY,
            'category_id' => $category?->id,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Showcase::TYPE_CUSTOM,
            'name' => 'Seleção Especial',
            'slug' => 'selecao-especial',
        ]);
    }
}

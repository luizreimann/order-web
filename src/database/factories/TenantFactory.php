<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'is_active' => true,
            'primary_color' => '#3B82F6',
            'secondary_color' => '#1E40AF',
            'logo_path' => null,
            'settings' => [],
        ];
    }

    public function withMercadoPago(string $publicKey = 'TEST-public-key', string $accessToken = 'TEST-access-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'settings' => array_merge($attributes['settings'] ?? [], [
                'payment' => [
                    'mercadopago' => [
                        'public_key' => $publicKey,
                        'access_token' => 'enc:' . encrypt($accessToken),
                        'webhook_secret' => 'enc:' . encrypt('test-webhook-secret'),
                    ],
                ],
            ]),
        ]);
    }
}

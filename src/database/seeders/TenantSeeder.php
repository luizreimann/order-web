<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['slug' => 'caroldoces'],
            [
                'name' => 'Carol Doces',
                'primary_color' => '#0d6efd',
                'secondary_color' => '#6c757d',
                'settings' => [
                    'home' => [
                        ['type' => 'hero', 'title' => 'Bem-vindo(a) à Carol Doces', 'subtitle' => 'Encomendas sob medida'],
                    ],
                ],
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $existingCount = Showcase::query()->forTenant($tenant->id)->count();

            if ($existingCount === 0) {
                Showcase::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Todos os Produtos',
                    'slug' => 'todos-os-produtos',
                    'type' => Showcase::TYPE_ALL,
                    'max_items' => 100,
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
            }
        }
    }
}

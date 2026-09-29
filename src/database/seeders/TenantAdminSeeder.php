<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantAdminSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'caroldoces')->firstOrFail();

        User::firstOrCreate(
            ['email' => 'admin@caroldoces.com'],
            [
                'name' => 'Admin Carol Doces',
                'password' => 'password',
                'tenant_id' => $tenant->id,
                'role' => 'tenant_admin',
            ]
        );
    }
}

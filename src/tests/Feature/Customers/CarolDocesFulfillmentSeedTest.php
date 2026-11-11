<?php

namespace Tests\Feature\Customers;

use App\Models\Tenant;
use Database\Seeders\CarolDocesDemoSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A demo da Carol Doces traz bairros de exemplo, sem nunca sobrescrever configuração existente. */
class CarolDocesFulfillmentSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeds_regions_once_and_is_idempotent(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(CarolDocesDemoSeeder::class);
        $this->seed(CarolDocesDemoSeeder::class);

        $delivery = Tenant::where('slug', 'caroldoces')->firstOrFail()->settings['delivery'];

        $this->assertTrue($delivery['enabled']);
        $this->assertSame(['Centro', 'Batel', 'Portão'], array_column($delivery['regions'], 'name'));
        $this->assertSame(2000, $delivery['minimum_order_cents']);
    }

    public function test_it_never_overwrites_what_the_store_already_configured(): void
    {
        $this->seed(TenantSeeder::class);
        $tenant = Tenant::where('slug', 'caroldoces')->firstOrFail();
        $tenant->update(['settings' => ['delivery' => ['enabled' => false, 'minimum_order_cents' => 500]]]);

        $this->seed(CarolDocesDemoSeeder::class);

        $delivery = $tenant->refresh()->settings['delivery'];
        $this->assertFalse($delivery['enabled']);
        $this->assertSame(500, $delivery['minimum_order_cents']);
        $this->assertArrayNotHasKey('regions', $delivery);
    }
}

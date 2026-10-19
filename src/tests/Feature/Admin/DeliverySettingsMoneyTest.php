<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Taxa de entrega / pedido mínimo: `(int) (float * 100)` truncava (1,15 * 100 = 114.999… => 114).
 * A conversão agora passa por {@see \App\Support\Money} (arredonda).
 */
class DeliverySettingsMoneyTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    public function test_values_whose_float_product_is_below_the_exact_cents_are_not_truncated(): void
    {
        $this->withoutVite();
        $tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => Role::TenantAdmin]), 'tenant_admin');

        $page = $this->get(route('tenant.admin.delivery', ['tenant' => 'tenant-a']))->assertOk();

        $response = $this->callLivewire(
            $this->snapshotFor($page->getContent(), 'admin.tenant.delivery-settings'),
            'save',
            [],
            ['minimum_order' => 1.15, 'default_fee' => 0.29, 'free_delivery_above' => 4.35],
        )->assertOk();

        $delivery = $tenant->refresh()->settings['delivery'];
        $this->assertSame(115, $delivery['minimum_order_cents']);
        $this->assertSame(29, $delivery['default_fee_cents']);
        $this->assertSame(435, $delivery['free_delivery_above_cents']);
        $this->assertNull($response->json('components.0.effects.redirect'));
    }
}

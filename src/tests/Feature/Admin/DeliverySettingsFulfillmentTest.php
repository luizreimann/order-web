<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Livewire\Admin\Tenant\DeliverySettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tela "Retirada e Entrega": liga/desliga retirada e entrega, taxa de retirada e bairros (nome +
 * preço). Dinheiro em centavos; ao menos uma forma de recebimento ligada; bairros sem nome repetido.
 */
class DeliverySettingsFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->tenant = Tenant::factory()->create(['slug' => 'loja-a', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]), 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
    }

    private function screen(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(DeliverySettings::class, ['tenant' => $this->tenant]);
    }

    public function test_the_defaults_are_pickup_on_delivery_off_and_no_regions(): void
    {
        $this->screen()
            ->assertSet('pickup_enabled', true)
            ->assertSet('delivery_enabled', false)
            ->assertSet('regions', [])
            ->assertDontSee('Bairros e taxas');
    }

    public function test_it_saves_the_switches_pickup_fee_and_regions_in_cents(): void
    {
        $this->screen()
            ->set('pickup_enabled', true)->set('pickup_fee', 2.5)
            ->set('delivery_enabled', true)->set('default_fee', 7)->set('free_delivery_above', 80)->set('minimum_order', 20)->set('estimated_time_minutes', 45)
            ->call('addRegion')->set('regions.0.name', 'Centro')->set('regions.0.fee', 5)
            ->call('addRegion')->set('regions.1.name', 'Bairro Alto')->set('regions.1.fee', 9.9)->set('regions.1.active', false)
            ->call('save')->assertHasNoErrors();

        $settings = $this->tenant->refresh()->settings;
        $this->assertSame(['enabled' => true, 'fee_cents' => 250], $settings['pickup']);
        $this->assertTrue($settings['delivery']['enabled']);
        $this->assertSame(700, $settings['delivery']['default_fee_cents']);
        $this->assertSame(8000, $settings['delivery']['free_delivery_above_cents']);
        $this->assertSame(2000, $settings['delivery']['minimum_order_cents']);
        $this->assertSame(45, $settings['delivery']['estimated_time_minutes']);
        $this->assertSame(['Centro', 500, true], [$settings['delivery']['regions'][0]['name'], $settings['delivery']['regions'][0]['fee_cents'], $settings['delivery']['regions'][0]['active']]);
        $this->assertSame(['Bairro Alto', 990, false], [$settings['delivery']['regions'][1]['name'], $settings['delivery']['regions'][1]['fee_cents'], $settings['delivery']['regions'][1]['active']]);
        $this->assertNotSame($settings['delivery']['regions'][0]['id'], $settings['delivery']['regions'][1]['id']);
    }

    public function test_saved_regions_come_back_with_the_same_ids_so_old_orders_keep_pointing_at_them(): void
    {
        $screen = $this->screen()->set('delivery_enabled', true)->call('addRegion')->set('regions.0.name', 'Centro')->call('save');
        $id = $this->tenant->refresh()->settings['delivery']['regions'][0]['id'];

        $again = $this->screen()->assertSet('regions.0.id', $id)->set('regions.0.fee', 3)->call('save');

        $this->assertSame($id, $this->tenant->refresh()->settings['delivery']['regions'][0]['id']);
        $this->assertSame(300, $this->tenant->settings['delivery']['regions'][0]['fee_cents']);
    }

    public function test_removing_a_region_drops_it_and_reindexes(): void
    {
        $this->screen()->set('delivery_enabled', true)
            ->call('addRegion')->set('regions.0.name', 'A')->set('regions.0.name', 'Alfa')
            ->call('addRegion')->set('regions.1.name', 'Beta')
            ->call('removeRegion', 0)
            ->assertSet('regions.0.name', 'Beta')
            ->call('save')->assertHasNoErrors();

        $this->assertCount(1, $this->tenant->refresh()->settings['delivery']['regions']);
    }

    public function test_at_least_one_way_to_receive_must_stay_on(): void
    {
        $this->screen()->set('pickup_enabled', false)->set('delivery_enabled', false)->call('save')->assertHasErrors('pickup_enabled');

        $this->assertNull($this->tenant->refresh()->settings['pickup'] ?? null);
    }

    public function test_delivery_alone_is_allowed(): void
    {
        $this->screen()->set('pickup_enabled', false)->set('delivery_enabled', true)->call('save')->assertHasNoErrors();

        $this->assertFalse($this->tenant->refresh()->settings['pickup']['enabled']);
    }

    public function test_region_validation_marks_each_bad_field(): void
    {
        $this->screen()->set('delivery_enabled', true)
            ->call('addRegion')->set('regions.0.name', '')->set('regions.0.fee', -1)
            ->call('save')
            ->assertHasErrors(['regions.0.name', 'regions.0.fee']);

        $this->assertNull($this->tenant->refresh()->settings['delivery'] ?? null);
    }

    public function test_two_regions_with_the_same_name_are_refused_ignoring_case_and_spaces(): void
    {
        $this->screen()->set('delivery_enabled', true)
            ->call('addRegion')->set('regions.0.name', 'Centro')
            ->call('addRegion')->set('regions.1.name', ' centro ')
            ->call('save')->assertHasErrors('regions');
    }

    public function test_the_region_count_is_capped(): void
    {
        $screen = $this->screen()->set('delivery_enabled', true);

        for ($i = 0; $i < 100; $i++) {
            $screen->call('addRegion');
        }

        $screen->call('addRegion')->assertHasErrors('regions')->assertCount('regions', 100);
    }

    public function test_a_huge_fee_is_refused(): void
    {
        $this->screen()->set('pickup_fee', 999999999)->call('save')->assertHasErrors('pickup_fee');
    }

    public function test_a_user_of_another_tenant_cannot_use_the_screen(): void
    {
        $other = Tenant::factory()->create(['slug' => 'loja-b', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['tenant_id' => $other->id, 'role' => Role::TenantAdmin]), 'tenant_admin');

        $this->get('/loja-a/admin/entrega')->assertStatus(302);
    }

    public function test_the_screen_renders_the_regions_and_the_add_button_when_delivery_is_on(): void
    {
        $this->screen()->set('delivery_enabled', true)->call('addRegion')
            ->assertSee('Bairros e taxas')
            ->assertSee('Adicionar bairro')
            ->assertSee('Remover');
    }

    public function test_clearing_a_numeric_field_saves_zero_instead_of_a_500(): void
    {
        // O navegador manda "" ao apagar um <input type=number>: as regras são `nullable`, então
        // a propriedade tem de aceitar null (antes: "Property [$minimum_order] not found", HTTP 500).
        $this->screen()
            ->set('delivery_enabled', true)
            ->set('minimum_order', null)->set('default_fee', null)->set('free_delivery_above', null)->set('pickup_fee', null)
            ->call('save')->assertHasNoErrors();

        $settings = $this->tenant->refresh()->settings;
        $this->assertSame(0, $settings['delivery']['minimum_order_cents']);
        $this->assertSame(0, $settings['delivery']['default_fee_cents']);
        $this->assertSame(0, $settings['delivery']['free_delivery_above_cents']);
        $this->assertSame(0, $settings['pickup']['fee_cents']);
    }
}

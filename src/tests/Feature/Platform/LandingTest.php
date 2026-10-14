<?php

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Landing mínima da plataforma em `/` (D7, E4.4).
 */
class LandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_serves_the_landing_without_redirect(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeaderMissing('Location');
        $response->assertSee('Order Web');
        $response->assertSee('encomendas');
    }

    public function test_landing_is_public_and_ignores_existing_tenants(): void
    {
        Tenant::factory()->create(['slug' => 'loja-qualquer', 'name' => 'Loja Qualquer']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('loja-qualquer');
        $response->assertDontSee('Loja Qualquer');
    }

    public function test_landing_does_not_hardcode_a_tenant_slug(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('caroldoces', strtolower($html));
        // nenhum link interno: a landing não aponta para loja nenhuma
        $this->assertDoesNotMatchRegularExpression('/href="\/[a-z0-9]/i', $html);
    }

    public function test_landing_is_served_by_a_plain_controller_not_livewire(): void
    {
        $route = app('router')->getRoutes()->match(request()->create('/'));

        $this->assertStringNotContainsString('App\\Livewire\\', $route->getActionName());
        $this->assertSame('platform.landing', $route->getName());
    }
}

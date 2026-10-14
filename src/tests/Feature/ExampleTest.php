<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz `/` é a landing da plataforma (D7): responde 200, sem redirecionar
     * para nenhuma loja. Cobertura detalhada em Platform\LandingTest.
     */
    public function test_the_application_serves_the_platform_landing_from_root(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Order Web');
    }
}

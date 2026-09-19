<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_from_root(): void
    {
        $response = $this->get('/');

        // A rota raiz redireciona para login ou tenant
        $response->assertRedirect();
    }
}

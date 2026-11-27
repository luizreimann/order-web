<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O crédito «Fornecido por» e a marca ficam só na configuração do WhatsApp.
 */
class ZaptriaCreditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'loja-a',
            'name' => 'Carol Doces',
            'is_active' => true,
        ]);
        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_the_whatsapp_screen_shows_the_credit_and_both_marks_exist(): void
    {
        $this->assertFileExists(public_path('images/zaptria.webp'));
        $this->assertFileExists(public_path('images/zaptria-dark.webp'));

        $html = (string) $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Fornecido por', $html);
        $this->assertSame(1, substr_count($html, 'href="https://zaptria.com.br"'));
        $this->assertSame(2, substr_count($html, 'alt="Zaptria"'));
        $this->assertStringContainsString('images/zaptria.webp', $html);
        $this->assertStringContainsString('images/zaptria-dark.webp', $html);
        $this->assertSame(2, substr_count($html, 'width="72"'));
        $this->assertSame(2, substr_count($html, 'height="24"'));
        $this->assertStringContainsString('.zaptria-credit-dark { display: none; }', $html);
        $this->assertStringContainsString('[data-bs-theme="dark"] .zaptria-credit-light { display: none; }', $html);
        $this->assertStringContainsString('[data-bs-theme="dark"] .zaptria-credit-dark { display: inline; }', $html);
    }

    public function test_contact_order_storefront_and_another_setting_hide_the_credit(): void
    {
        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $pages = [
            'contato' => $this->get(route('tenant.admin.contact', ['tenant' => 'loja-a'])),
            'pedido' => $this->get(route('tenant.admin.orders.show', [
                'tenant' => 'loja-a',
                'orderId' => $order->id,
            ])),
            'vitrine' => $this->get(route('tenant.home', ['tenant' => 'loja-a'])),
            'endereco' => $this->get(route('tenant.admin.address', ['tenant' => 'loja-a'])),
        ];

        foreach ($pages as $name => $response) {
            $this->assertCreditIsAbsent($name, $response);
        }
    }

    private function assertCreditIsAbsent(string $name, TestResponse $response): void
    {
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('Fornecido por', $html, $name);
        $this->assertStringNotContainsString('images/zaptria', $html, $name);
        $this->assertStringNotContainsString('zaptria.com.br', $html, $name);
    }
}

<?php

namespace Tests\Unit\Gateways;

use App\Enums\PaymentMethod;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Gateways\MercadoPagoGateway;
use App\Gateways\PaymentGatewayManager;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Gateways\FakeGateway;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGatewayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(PaymentGatewayManager::class);
    }

    public function test_it_is_a_container_singleton(): void
    {
        $this->assertSame($this->manager, app(PaymentGatewayManager::class));
    }

    public function test_mercado_pago_methods_resolve_to_the_mercado_pago_gateway_by_provider(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([PaymentMethod::MP_PIX, PaymentMethod::MP_CREDIT_CARD, PaymentMethod::MP_DEBIT_CARD, 'mp_pix'] as $method) {
            $this->assertInstanceOf(MercadoPagoGateway::class, $this->manager->forMethod($method, $tenant));
        }

        $this->assertSame('mercadopago', $this->manager->forProvider('mercadopago')->key());
    }

    public function test_the_same_gateway_instance_is_reused(): void
    {
        $this->assertSame($this->manager->forProvider('mercadopago'), $this->manager->forProvider('mercadopago'));
    }

    public function test_manual_methods_have_no_gateway(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([PaymentMethod::CASH_ON_DELIVERY, PaymentMethod::PIX_MANUAL, 'card_on_delivery'] as $method) {
            try {
                $this->manager->forMethod($method, $tenant);
                $this->fail('método manual não deveria resolver gateway');
            } catch (UnsupportedPaymentMethodException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_method_or_provider_throws(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(UnsupportedPaymentMethodException::class);
        $this->manager->forMethod('bitcoin', $tenant);
    }

    public function test_unknown_provider_throws(): void
    {
        $this->assertFalse($this->manager->has('fake'), 'o FakeGateway só existe em teste, registrado explicitamente');

        $this->expectException(UnsupportedPaymentMethodException::class);
        $this->manager->forProvider('fake');
    }

    public function test_extend_registers_a_new_provider(): void
    {
        $fake = new FakeGateway;
        $this->manager->extend('fake', fn () => $fake);

        $this->assertTrue($this->manager->has('fake'));
        $this->assertSame($fake, $this->manager->forProvider('fake'));
        $this->assertContains('fake', $this->manager->providers());
    }

    public function test_the_tenant_method_configuration_can_select_another_registered_provider(): void
    {
        $fake = new FakeGateway;
        $this->manager->extend('fake', fn () => $fake);

        $tenant = Tenant::factory()->create(['settings' => ['payment' => ['methods' => [
            ['id' => 'mp_pix', 'provider' => 'fake', 'enabled' => true],
            ['id' => 'mp_credit_card', 'provider' => 'mercadopago', 'enabled' => true],
        ]]]]);
        $other = Tenant::factory()->create();

        $this->assertSame($fake, $this->manager->forMethod('mp_pix', $tenant));
        $this->assertInstanceOf(MercadoPagoGateway::class, $this->manager->forMethod('mp_credit_card', $tenant));
        $this->assertInstanceOf(MercadoPagoGateway::class, $this->manager->forMethod('mp_pix', $other), 'a escolha é por tenant');
    }

    public function test_a_tenant_cannot_select_an_unregistered_provider(): void
    {
        $tenant = Tenant::factory()->create(['settings' => ['payment' => ['methods' => [
            ['id' => 'mp_pix', 'provider' => 'Illuminate\\Support\\Facades\\Artisan', 'enabled' => true],
        ]]]]);

        $this->expectException(UnsupportedPaymentMethodException::class);
        $this->manager->forMethod('mp_pix', $tenant);
    }
}

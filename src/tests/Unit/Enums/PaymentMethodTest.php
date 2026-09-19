<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethod;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    public function test_is_mercado_pago_returns_true_for_mp_methods(): void
    {
        $this->assertTrue(PaymentMethod::MP_PIX->isMercadoPago());
        $this->assertTrue(PaymentMethod::MP_CREDIT_CARD->isMercadoPago());
        $this->assertTrue(PaymentMethod::MP_DEBIT_CARD->isMercadoPago());
    }

    public function test_is_mercado_pago_returns_false_for_manual_methods(): void
    {
        $this->assertFalse(PaymentMethod::CASH_ON_DELIVERY->isMercadoPago());
        $this->assertFalse(PaymentMethod::CARD_ON_DELIVERY->isMercadoPago());
        $this->assertFalse(PaymentMethod::PIX_MANUAL->isMercadoPago());
    }

    public function test_is_manual_returns_true_for_manual_methods(): void
    {
        $this->assertTrue(PaymentMethod::CASH_ON_DELIVERY->isManual());
        $this->assertTrue(PaymentMethod::CARD_ON_DELIVERY->isManual());
        $this->assertTrue(PaymentMethod::PIX_MANUAL->isManual());
    }

    public function test_is_manual_returns_false_for_mp_methods(): void
    {
        $this->assertFalse(PaymentMethod::MP_PIX->isManual());
        $this->assertFalse(PaymentMethod::MP_CREDIT_CARD->isManual());
        $this->assertFalse(PaymentMethod::MP_DEBIT_CARD->isManual());
    }

    public function test_provider_returns_correct_value(): void
    {
        $this->assertEquals('manual', PaymentMethod::CASH_ON_DELIVERY->provider());
        $this->assertEquals('manual', PaymentMethod::PIX_MANUAL->provider());
        $this->assertEquals('mercadopago', PaymentMethod::MP_PIX->provider());
        $this->assertEquals('mercadopago', PaymentMethod::MP_CREDIT_CARD->provider());
    }

    public function test_label_returns_correct_value(): void
    {
        $this->assertEquals('Dinheiro (na entrega)', PaymentMethod::CASH_ON_DELIVERY->label());
        $this->assertEquals('PIX (Chave)', PaymentMethod::PIX_MANUAL->label());
        $this->assertEquals('PIX (Mercado Pago)', PaymentMethod::MP_PIX->label());
        $this->assertEquals('Cartão de crédito (Mercado Pago)', PaymentMethod::MP_CREDIT_CARD->label());
    }

    public function test_all_ids_returns_all_method_ids(): void
    {
        $ids = PaymentMethod::allIds();

        $this->assertCount(6, $ids);
        $this->assertContains('cash_on_delivery', $ids);
        $this->assertContains('card_on_delivery', $ids);
        $this->assertContains('pix_manual', $ids);
        $this->assertContains('mp_pix', $ids);
        $this->assertContains('mp_credit_card', $ids);
        $this->assertContains('mp_debit_card', $ids);
    }

    public function test_manual_ids_returns_only_manual_method_ids(): void
    {
        $ids = PaymentMethod::manualIds();

        $this->assertCount(3, $ids);
        $this->assertContains('cash_on_delivery', $ids);
        $this->assertContains('card_on_delivery', $ids);
        $this->assertContains('pix_manual', $ids);
        $this->assertNotContains('mp_pix', $ids);
    }

    public function test_mercado_pago_ids_returns_only_mp_method_ids(): void
    {
        $ids = PaymentMethod::mercadoPagoIds();

        $this->assertCount(3, $ids);
        $this->assertContains('mp_pix', $ids);
        $this->assertContains('mp_credit_card', $ids);
        $this->assertContains('mp_debit_card', $ids);
        $this->assertNotContains('cash_on_delivery', $ids);
    }

    public function test_default_config_returns_correct_structure(): void
    {
        $config = PaymentMethod::defaultConfig();

        $this->assertCount(6, $config);

        foreach ($config as $method) {
            $this->assertArrayHasKey('id', $method);
            $this->assertArrayHasKey('provider', $method);
            $this->assertArrayHasKey('label', $method);
            $this->assertArrayHasKey('enabled', $method);
            $this->assertArrayHasKey('instructions', $method);
            $this->assertArrayHasKey('config', $method);
        }
    }

    public function test_default_config_has_manual_methods_enabled_by_default(): void
    {
        $config = PaymentMethod::defaultConfig();

        foreach ($config as $method) {
            $isManual = PaymentMethod::tryFrom($method['id'])?->isManual() ?? false;
            $this->assertEquals($isManual, $method['enabled']);
        }
    }

    public function test_default_config_pix_manual_has_pix_key_config(): void
    {
        $config = PaymentMethod::defaultConfig();
        $pixManual = collect($config)->firstWhere('id', 'pix_manual');

        $this->assertArrayHasKey('pix_key', $pixManual['config']);
    }

    public function test_try_from_id_returns_correct_enum(): void
    {
        $this->assertEquals(PaymentMethod::MP_PIX, PaymentMethod::tryFromId('mp_pix'));
        $this->assertEquals(PaymentMethod::CASH_ON_DELIVERY, PaymentMethod::tryFromId('cash_on_delivery'));
    }

    public function test_try_from_id_returns_null_for_invalid_id(): void
    {
        $this->assertNull(PaymentMethod::tryFromId('invalid_method'));
    }

    public function test_is_mercado_pago_id_returns_correct_value(): void
    {
        $this->assertTrue(PaymentMethod::isMercadoPagoId('mp_pix'));
        $this->assertTrue(PaymentMethod::isMercadoPagoId('mp_credit_card'));
        $this->assertFalse(PaymentMethod::isMercadoPagoId('cash_on_delivery'));
        $this->assertFalse(PaymentMethod::isMercadoPagoId('invalid_method'));
    }
}

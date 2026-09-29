<?php

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Support\TenantHelper;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class TenantHelperTest extends TestCase
{
    public function test_is_open_returns_true_when_not_configured(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $this->assertTrue(TenantHelper::isOpen($tenant));
    }

    public function test_is_open_returns_true_when_disabled(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => false,
            ],
        ];

        $this->assertTrue(TenantHelper::isOpen($tenant));
    }

    public function test_is_open_returns_false_when_paused(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'paused' => true,
            ],
        ];

        $this->assertFalse(TenantHelper::isOpen($tenant));
    }

    public function test_is_open_returns_true_when_auto_close_disabled(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'auto_close' => false,
            ],
        ];

        $this->assertTrue(TenantHelper::isOpen($tenant));
    }

    public function test_is_open_returns_false_when_day_is_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-12 10:00:00', 'America/Sao_Paulo')); // Monday

        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'auto_close' => true,
                'timezone' => 'America/Sao_Paulo',
                'schedule' => [
                    'monday' => ['closed' => true],
                ],
            ],
        ];

        $this->assertFalse(TenantHelper::isOpen($tenant));

        Carbon::setTestNow();
    }

    public function test_is_open_returns_true_when_within_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-12 14:00:00', 'America/Sao_Paulo')); // Monday 2pm

        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'auto_close' => true,
                'timezone' => 'America/Sao_Paulo',
                'schedule' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                ],
            ],
        ];

        $this->assertTrue(TenantHelper::isOpen($tenant));

        Carbon::setTestNow();
    }

    public function test_is_open_returns_false_when_outside_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-12 20:00:00', 'America/Sao_Paulo')); // Monday 8pm

        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'auto_close' => true,
                'timezone' => 'America/Sao_Paulo',
                'schedule' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                ],
            ],
        ];

        $this->assertFalse(TenantHelper::isOpen($tenant));

        Carbon::setTestNow();
    }

    public function test_get_formatted_address_returns_null_when_not_configured(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $this->assertNull(TenantHelper::getFormattedAddress($tenant));
    }

    public function test_get_formatted_address_returns_null_when_hidden(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'address' => [
                'street' => 'Rua Teste',
                'number' => '123',
                'show_on_storefront' => false,
            ],
        ];

        $this->assertNull(TenantHelper::getFormattedAddress($tenant));
    }

    public function test_get_formatted_address_returns_formatted_string(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'address' => [
                'street' => 'Rua Teste',
                'number' => '123',
                'complement' => 'Sala 1',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01234-567',
                'show_on_storefront' => true,
            ],
        ];

        $result = TenantHelper::getFormattedAddress($tenant);

        $this->assertStringContainsString('Rua Teste', $result);
        $this->assertStringContainsString('123', $result);
        $this->assertStringContainsString('Centro', $result);
        $this->assertStringContainsString('São Paulo', $result);
        $this->assertStringContainsString('CEP: 01234-567', $result);
    }

    public function test_get_whatsapp_url_returns_null_when_not_configured(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $this->assertNull(TenantHelper::getWhatsAppUrl($tenant));
    }

    public function test_get_whatsapp_url_returns_url_with_number(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'contact' => [
                'whatsapp' => '5511999999999',
            ],
        ];

        $result = TenantHelper::getWhatsAppUrl($tenant);

        $this->assertEquals('https://wa.me/5511999999999', $result);
    }

    public function test_get_whatsapp_url_includes_message(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'contact' => [
                'whatsapp' => '5511999999999',
                'whatsapp_message' => 'Olá!',
            ],
        ];

        $result = TenantHelper::getWhatsAppUrl($tenant);

        $this->assertStringContainsString('text=Ol%C3%A1%21', $result);
    }

    public function test_get_delivery_info_returns_defaults(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $result = TenantHelper::getDeliveryInfo($tenant);

        $this->assertEquals(0, $result['minimum_order']);
        $this->assertEquals(0, $result['fee']);
        $this->assertNull($result['estimated_time']);
        $this->assertEquals(0, $result['free_above']);
    }

    public function test_get_delivery_info_converts_cents_to_reais(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'delivery' => [
                'minimum_order_cents' => 2000,
                'default_fee_cents' => 500,
                'estimated_time_minutes' => 45,
                'free_delivery_above_cents' => 10000,
            ],
        ];

        $result = TenantHelper::getDeliveryInfo($tenant);

        $this->assertEquals(20.0, $result['minimum_order']);
        $this->assertEquals(5.0, $result['fee']);
        $this->assertEquals(45, $result['estimated_time']);
        $this->assertEquals(100.0, $result['free_above']);
    }

    public function test_get_social_links_returns_empty_when_not_configured(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $result = TenantHelper::getSocialLinks($tenant);

        $this->assertEmpty($result);
    }

    public function test_get_social_links_filters_null_values(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'social' => [
                'instagram' => 'https://instagram.com/test',
                'facebook' => null,
                'tiktok' => '',
            ],
        ];

        $result = TenantHelper::getSocialLinks($tenant);

        $this->assertArrayHasKey('instagram', $result);
        $this->assertArrayNotHasKey('facebook', $result);
        $this->assertArrayNotHasKey('tiktok', $result);
    }

    public function test_get_contact_info_returns_defaults(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [];

        $result = TenantHelper::getContactInfo($tenant);

        $this->assertNull($result['phone']);
        $this->assertNull($result['whatsapp']);
        $this->assertNull($result['email']);
        $this->assertFalse($result['show_whatsapp_button']);
    }

    public function test_get_contact_info_returns_configured_values(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'contact' => [
                'phone' => '(11) 99999-9999',
                'whatsapp' => '5511999999999',
                'email' => 'test@example.com',
                'show_whatsapp_button' => true,
            ],
        ];

        $result = TenantHelper::getContactInfo($tenant);

        $this->assertEquals('(11) 99999-9999', $result['phone']);
        $this->assertEquals('5511999999999', $result['whatsapp']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertTrue($result['show_whatsapp_button']);
    }

    public function test_get_business_hours_for_display_returns_empty_when_disabled(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => false,
            ],
        ];

        $result = TenantHelper::getBusinessHoursForDisplay($tenant);

        $this->assertEmpty($result);
    }

    public function test_get_business_hours_for_display_returns_formatted_schedule(): void
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'business_hours' => [
                'enabled' => true,
                'schedule' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
                    'tuesday' => ['closed' => true],
                ],
            ],
        ];

        $result = TenantHelper::getBusinessHoursForDisplay($tenant);

        $this->assertCount(7, $result);
        $this->assertEquals('Segunda', $result[0]['day']);
        $this->assertEquals('08:00 - 18:00', $result[0]['hours']);
        $this->assertEquals('Terça', $result[1]['day']);
        $this->assertEquals('Fechado', $result[1]['hours']);
    }

    public function test_get_seo_data_returns_defaults(): void
    {
        $tenant = new Tenant;
        $tenant->name = 'Minha Loja';
        $tenant->settings = [];

        $result = TenantHelper::getSeoData($tenant);

        $this->assertEquals('Minha Loja', $result['title']);
        $this->assertEquals('', $result['description']);
        $this->assertNull($result['og_image']);
        $this->assertNull($result['google_analytics_id']);
    }

    public function test_get_seo_data_returns_configured_values(): void
    {
        $tenant = new Tenant;
        $tenant->name = 'Minha Loja';
        $tenant->settings = [
            'seo' => [
                'meta_title' => 'Título SEO',
                'meta_description' => 'Descrição SEO',
                'og_image_path' => 'tenants/123/og.jpg',
                'google_analytics_id' => 'G-123456',
            ],
        ];

        $result = TenantHelper::getSeoData($tenant);

        $this->assertEquals('Título SEO', $result['title']);
        $this->assertEquals('Descrição SEO', $result['description']);
        $this->assertEquals('tenants/123/og.jpg', $result['og_image']);
        $this->assertEquals('G-123456', $result['google_analytics_id']);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EncryptionHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Admin > Pagamentos > Integrações: o passo a passo do webhook do Mercado Pago (API de Orders).
 * A URL a cadastrar no painel sai de `WebhookUrl::for(APP_URL, mercadopago, tenant id)`; o
 * token e o Webhook Secret continuam write-only (nunca no HTML).
 */
class PaymentSettingsWebhookGuideTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private const URL = '/tenant-a/admin/pagamentos';

    private const COMPONENT = 'admin.tenant.payment-settings';

    private const TOKEN = 'APP_USR-1234567890-SECRETTOKEN-9876';

    private const WEBHOOK = 'whsec_ABCDEFGHIJKLMNOP-4321';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'tenant-a',
            'is_active' => true,
            'settings' => ['payment' => ['mercadopago' => [
                'public_key' => 'APP_USR-public-key-visible',
                'access_token' => EncryptionHelper::encryptIfNeeded(self::TOKEN),
                'webhook_secret' => EncryptionHelper::encryptIfNeeded(self::WEBHOOK),
            ]]],
        ]);

        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]), 'tenant_admin');
    }

    /** HTML do componente já na aba de integrações (endpoint HTTP real do Livewire). */
    private function integrationsHtml(): string
    {
        $page = $this->get(self::URL)->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $page, self::COMPONENT);

        return (string) $this->callLivewire($snapshot, 'setTab', ['integrations'])->assertOk()->json('components.0.effects.html');
    }

    public function test_with_a_public_app_url_the_exact_webhook_url_and_the_order_topic_are_shown(): void
    {
        config(['app.url' => 'https://app.quitanda.app']);

        $html = $this->integrationsHtml();

        $expected = 'https://app.quitanda.app/webhooks/mercadopago/'.$this->tenant->id;
        $this->assertStringContainsString('value="'.$expected.'"', $html);
        foreach (['Notificações (webhook) do Mercado Pago', 'Order (Mercado Pago)', 'Webhook Secret', 'Suas integrações'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Indisponível em ambiente local', $html);
    }

    /** @return array<string, array{string}> */
    public static function nonPublicAppUrls(): array
    {
        return [
            'localhost' => ['http://localhost'],
            'localhost com porta' => ['http://localhost:8080'],
            'http público' => ['http://app.quitanda.app'],
            'rede privada' => ['https://192.168.0.10'],
            'vazio' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPublicAppUrls')]
    public function test_with_a_local_app_url_it_warns_instead_of_showing_a_url_that_would_never_be_reached(string $appUrl): void
    {
        config(['app.url' => $appUrl]);

        $html = $this->integrationsHtml();

        $this->assertStringContainsString('Indisponível em ambiente local', $html);
        $this->assertStringContainsString('APP_URL', $html);
        $this->assertStringNotContainsString('/webhooks/mercadopago/', $html);
    }

    public function test_the_token_and_the_webhook_secret_never_appear_in_the_html(): void
    {
        config(['app.url' => 'https://app.quitanda.app']);

        $html = $this->integrationsHtml();

        foreach ([self::TOKEN, self::WEBHOOK, base64_encode(self::TOKEN), base64_encode(self::WEBHOOK)] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        // Só a máscara dos 4 últimos caracteres.
        $this->assertStringContainsString('••••9876', $html);
        $this->assertStringContainsString('••••4321', $html);

        // E a página completa (snapshot do Livewire incluído) também não os traz.
        $page = (string) $this->get(self::URL)->assertOk()->getContent();
        foreach ([self::TOKEN, self::WEBHOOK] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
    }
}

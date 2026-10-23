<?php

namespace Tests\Unit\Support;

use App\Support\WebhookUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL que o lojista cadastra no painel do Mercado Pago (Webhooks, tópico Order). Só existe para
 * `APP_URL` público e `https`: o MP não alcança `localhost`/rede privada e recusa URLs `http`.
 */
class WebhookUrlTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unusableBases(): array
    {
        return [
            'localhost' => ['http://localhost'],
            'localhost com porta' => ['http://localhost:8080'],
            'https localhost' => ['https://localhost'],
            'loopback' => ['http://127.0.0.1'],
            'rede privada' => ['https://192.168.0.10'],
            'tld de teste' => ['https://loja.test'],
            'http público' => ['http://app.quitanda.app'],
            'porta não padrão' => ['https://app.quitanda.app:8443'],
            'com query' => ['https://app.quitanda.app?x=1'],
            'com credenciais' => ['https://user:pass@app.quitanda.app'],
            'nome de serviço docker' => ['https://app'],
            'vazio' => [''],
            'com espaço' => ['https://app.quitanda.app '],
        ];
    }

    #[DataProvider('unusableBases')]
    public function test_a_non_public_or_non_https_base_yields_no_url(string $base): void
    {
        $this->assertNull(WebhookUrl::for($base, 'mercadopago', 'tenant-1'));
    }

    public function test_a_non_string_base_yields_no_url(): void
    {
        $this->assertNull(WebhookUrl::for(null, 'mercadopago', 'tenant-1'));
        $this->assertNull(WebhookUrl::for(['https://app.quitanda.app'], 'mercadopago', 'tenant-1'));
    }

    public function test_a_public_https_base_yields_the_tenant_url(): void
    {
        $this->assertSame('https://app.quitanda.app/webhooks/mercadopago/abc-123', WebhookUrl::for('https://app.quitanda.app', 'mercadopago', 'abc-123'));
        $this->assertSame('https://x.ngrok-free.app/webhooks/mercadopago/abc-123', WebhookUrl::for('https://x.ngrok-free.app/', 'mercadopago', 'abc-123'));
    }

    public function test_the_tenant_id_and_gateway_are_url_encoded(): void
    {
        $this->assertSame('https://app.quitanda.app/webhooks/mercadopago/a%2Fb', WebhookUrl::for('https://app.quitanda.app', 'mercadopago', 'a/b'));
    }
}

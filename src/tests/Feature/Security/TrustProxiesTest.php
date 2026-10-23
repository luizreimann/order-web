<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `TRUSTED_PROXIES` (config `app.trusted_proxies`, lida em tempo de REQUISIÇÃO por
 * `App\Http\Middleware\TrustProxies`): atrás de um túnel/proxy HTTPS o esquema e o host vêm em
 * `X-Forwarded-*`, mas esses cabeçalhos são FORJÁVEIS por qualquer cliente. Por isso o padrão é
 * não confiar em ninguém, e só os IPs listados (ou `*`) são ouvidos.
 *
 * Importante para pagamentos: links, `route()` e redirecionamentos saem com o esquema que o app
 * "enxerga"; atrás de um túnel sem esta configuração sairiam `http://` (conteúdo misto/URL errada).
 */
class TrustProxiesTest extends TestCase
{
    private const FORWARDED = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'forjado.example.net',
        'X-Forwarded-Port' => '443',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__probe/proxy/trust/check', fn (Request $request) => response()->json([
            // Um gerador NOVO (como num processo recém-iniciado): o do contêiner guarda raiz/esquema
            // da requisição anterior do mesmo teste.
            'url' => (new UrlGenerator(app('router')->getRoutes(), $request))->to('/x'),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
        ]));
    }

    protected function tearDown(): void
    {
        // O Symfony guarda os proxies confiáveis em estado ESTÁTICO: sem isto vazaria para os outros testes.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function probe(array $headers = [], string $remoteAddr = '127.0.0.1'): array
    {
        // Em produção cada requisição é um processo/worker novo; aqui o estado estático do Symfony
        // sobraria da requisição anterior do mesmo teste.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders($headers)
            ->getJson('/__probe/proxy/trust/check')
            ->assertOk()
            ->json();
    }

    public function test_the_default_is_to_trust_no_proxy(): void
    {
        $this->assertSame('', config('app.trusted_proxies'));
        $this->assertStringContainsString('TRUSTED_PROXIES=', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_without_trusted_proxies_a_forged_forwarded_proto_is_ignored(): void
    {
        config(['app.trusted_proxies' => '']);

        $seen = $this->probe(self::FORWARDED);

        $this->assertStringStartsWith('http://', $seen['url'], 'o URL gerado segue http://');
        $this->assertFalse($seen['secure']);
        $this->assertSame('http', $seen['scheme']);
        $this->assertNotSame('forjado.example.net', $seen['host'], 'X-Forwarded-Host forjado também é ignorado');
        $this->assertStringNotContainsString('forjado.example.net', $seen['url']);
    }

    public function test_with_a_wildcard_the_forwarded_proto_and_host_are_honoured(): void
    {
        config(['app.trusted_proxies' => '*']);

        $seen = $this->probe(self::FORWARDED);

        $this->assertStringStartsWith('https://', $seen['url'], 'atrás de túnel os links saem https://');
        $this->assertTrue($seen['secure']);
        $this->assertSame('forjado.example.net', $seen['host']);
    }

    public function test_with_a_wildcard_but_no_forwarded_headers_nothing_changes(): void
    {
        config(['app.trusted_proxies' => '*']);

        $seen = $this->probe();

        $this->assertStringStartsWith('http://', $seen['url']);
        $this->assertFalse($seen['secure']);
    }

    public function test_a_specific_ip_trusts_only_that_proxy(): void
    {
        config(['app.trusted_proxies' => '10.0.0.5']);

        // Veio do proxy configurado: confia.
        $trusted = $this->probe(self::FORWARDED, '10.0.0.5');
        $this->assertStringStartsWith('https://', $trusted['url']);
        $this->assertTrue($trusted['secure']);

        // Veio de OUTRO IP (cliente forjando o cabeçalho): ignora.
        $untrusted = $this->probe(self::FORWARDED, '203.0.113.9');
        $this->assertStringStartsWith('http://', $untrusted['url']);
        $this->assertFalse($untrusted['secure']);
        $this->assertNotSame('forjado.example.net', $untrusted['host']);
    }

    public function test_a_comma_separated_list_with_spaces_trusts_each_listed_proxy_and_no_other(): void
    {
        config(['app.trusted_proxies' => '10.0.0.5, 10.0.0.6 ,172.18.0.2']);

        foreach (['10.0.0.5', '10.0.0.6', '172.18.0.2'] as $proxy) {
            $this->assertTrue($this->probe(self::FORWARDED, $proxy)['secure'], "{$proxy} deveria ser confiável");
        }

        foreach (['10.0.0.7', '172.18.0.3', '127.0.0.1'] as $other) {
            $this->assertFalse($this->probe(self::FORWARDED, $other)['secure'], "{$other} não deveria ser confiável");
        }
    }

    public function test_the_setting_is_read_per_request_not_at_boot(): void
    {
        config(['app.trusted_proxies' => '']);
        $this->assertFalse($this->probe(self::FORWARDED)['secure']);

        config(['app.trusted_proxies' => '*']);
        $this->assertTrue($this->probe(self::FORWARDED)['secure']);

        config(['app.trusted_proxies' => '']);
        $this->assertFalse($this->probe(self::FORWARDED)['secure']);
    }
}

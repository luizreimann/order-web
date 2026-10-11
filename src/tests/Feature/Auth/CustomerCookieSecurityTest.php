<?php

namespace Tests\Feature\Auth;

use App\Livewire\Tenant\Auth\LoginPopup;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * E1.8 / ARC-003 — o flag `Secure` dos cookies do cliente (sessão e
 * `login_popup_dismissed_*`) não pode ser fixo: deriva de `session.secure`
 * e, quando indefinido, do ambiente (produção) ou da requisição HTTPS.
 */
class CustomerCookieSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->service = app(CustomerAuthService::class);
    }

    public function test_cookie_de_sessao_sem_secure_em_ambiente_local_testing(): void
    {
        config(['session.secure' => false]);

        $cookie = $this->service->createCookie($this->tenant, 'token');

        $this->assertFalse($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function test_cookie_de_sessao_secure_quando_session_secure_true(): void
    {
        config(['session.secure' => true]);

        $cookie = $this->service->createCookie($this->tenant, 'token');

        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function test_cookie_de_sessao_secure_em_producao_quando_config_indefinida(): void
    {
        config(['session.secure' => null]);
        $this->app['env'] = 'production';

        $this->assertTrue($this->service->createCookie($this->tenant, 'token')->isSecure());
    }

    public function test_cookie_de_sessao_sem_secure_em_local_quando_config_indefinida_e_http(): void
    {
        config(['session.secure' => null]);

        $this->assertFalse($this->service->createCookie($this->tenant, 'token')->isSecure());
    }

    public function test_cookie_de_sessao_secure_quando_requisicao_https_e_config_indefinida(): void
    {
        config(['session.secure' => null]);
        request()->server->set('HTTPS', 'on');

        $this->assertTrue($this->service->createCookie($this->tenant, 'token')->isSecure());
    }

    public function test_session_secure_explicito_false_vence_producao(): void
    {
        config(['session.secure' => false]);
        $this->app['env'] = 'production';

        $this->assertFalse($this->service->createCookie($this->tenant, 'token')->isSecure());
    }

    public function test_config_session_secure_default_acompanha_ambiente(): void
    {
        $config = require base_path('config/session.php');

        // Em testing (APP_ENV=testing, sem SESSION_SECURE_COOKIE) não é secure.
        $this->assertFalse($config['secure']);
    }

    public function test_cookie_apagado_mantem_atributos_coerentes(): void
    {
        config(['session.secure' => true]);

        $cookie = $this->service->deleteCookie($this->tenant);

        $this->assertSame($this->service->getCookieName($this->tenant), $cookie->getName());
        $this->assertNull($cookie->getValue());
        $this->assertTrue($cookie->isCleared());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
    }

    public function test_cookie_apagado_secure_em_producao_quando_config_indefinida(): void
    {
        config(['session.secure' => null]);
        $this->app['env'] = 'production';

        $this->assertTrue($this->service->deleteCookie($this->tenant)->isSecure());
    }

    public function test_cookie_apagado_sem_secure_em_local(): void
    {
        config(['session.secure' => false]);

        $this->assertFalse($this->service->deleteCookie($this->tenant)->isSecure());
    }

    public function test_login_popup_emite_cookies_secure_quando_configurado(): void
    {
        config(['session.secure' => true]);

        Livewire::test(LoginPopup::class, ['tenant' => $this->tenant])
            ->set('phone', '(11) 99999-0000')
            ->call('login')
            ->call('dismiss');

        $queued = $this->queuedByName();

        $session = $queued[$this->service->getCookieName($this->tenant)] ?? null;
        $dismissed = $queued['login_popup_dismissed_'.$this->tenant->id] ?? null;

        $this->assertNotNull($session);
        $this->assertNotNull($dismissed);

        foreach ([$session, $dismissed] as $cookie) {
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        }
    }

    public function test_login_popup_emite_cookies_sem_secure_em_local(): void
    {
        config(['session.secure' => false]);

        Livewire::test(LoginPopup::class, ['tenant' => $this->tenant])
            ->set('phone', '(11) 99999-0000')
            ->call('login')
            ->call('dismiss');

        $queued = $this->queuedByName();

        $this->assertCount(2, $queued);

        foreach ($queued as $cookie) {
            $this->assertFalse($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        }
    }

    /**
     * @return array<string, Cookie>
     */
    private function queuedByName(): array
    {
        $byName = [];

        foreach (cookie()->getQueuedCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }
}

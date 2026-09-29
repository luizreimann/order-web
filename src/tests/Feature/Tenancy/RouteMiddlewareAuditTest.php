<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureTenantAdminAuthenticated;
use App\Http\Middleware\ResolveTenantFromPath;
use App\Http\Middleware\TenantCustomerAuth;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Auditoria da tabela de rotas.
 *
 * Trava contra ESQUECIMENTO: hoje o isolamento de tenant depende de a rota ter
 * `tenant` (= {@see ResolveTenantFromPath}) na pilha, porque é ele que popula o
 * {@see \App\Support\TenantContext} de que o global scope vive — e, via
 * `Livewire::addPersistentMiddleware()`, é também o que faz o `TenantContext`
 * existir durante o `POST /livewire/update`. Uma rota nova registrada fora do
 * prefixo `{tenant}` vaza silenciosamente: nenhum teste existente falharia.
 *
 * Com este teste, adicionar tal rota obriga a uma decisão CONSCIENTE — atualizar
 * a allowlist abaixo, com justificativa — em vez de passar batido.
 *
 * A allowlist é a lista das exceções legítimas conhecidas. Nunca adicione uma
 * entrada aqui só para o teste passar: se a rota serve página ou componente de
 * tenant, o certo é movê-la para dentro do grupo `tenant`.
 */
class RouteMiddlewareAuditTest extends TestCase
{
    /**
     * Rotas que legitimamente NÃO têm contexto de tenant.
     *
     * @var list<string>
     */
    private const ALLOWED_NON_TENANT_URIS = [
        // Redirect de cortesia da raiz para a loja padrão. Não lê dado nenhum.
        '/',
        // Health check do Laravel (`health` em bootstrap/app.php).
        'up',
        // Webhook do Mercado Pago: chega SEM tenant no path por definição — o
        // tenant é descoberto pelo id do pagamento, via `withoutTenantScope()`.
        // Coberto por tests/Feature/Tenancy/WebhookCrossTenantTest.php.
        'webhooks/mercadopago',
        // Rota do disco público (`storage:link` emulado pelo Laravel).
        'storage/{path}',
    ];

    /**
     * Prefixos internos de pacotes (não são rotas da aplicação).
     *
     * `livewire/update` é o caso especial importante: ele NÃO tem `tenant` na
     * pilha, e é exatamente por isso que existe
     * `Livewire::addPersistentMiddleware()` — o middleware da rota ORIGINAL do
     * componente é re-aplicado a partir de `memo.path`. Ver
     * `LivewirePersistentMiddlewareTest`.
     *
     * @var list<string>
     */
    private const ALLOWED_NON_TENANT_PREFIXES = [
        'livewire/',
    ];

    /**
     * Rotas de `{tenant}/admin` que legitimamente não exigem sessão de admin.
     *
     * @var list<string>
     */
    private const ADMIN_URIS_WITHOUT_ADMIN_SESSION = [
        '{tenant}/admin/login',
    ];

    /**
     * @return list<Route>
     */
    private function appRoutes(): array
    {
        return array_values(app('router')->getRoutes()->getRoutes());
    }

    /**
     * @return list<string>
     */
    private function middlewareOf(Route $route): array
    {
        return array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            'is_string'
        ));
    }

    private function isAllowlisted(Route $route): bool
    {
        $uri = $route->uri();

        if (in_array($uri, self::ALLOWED_NON_TENANT_URIS, true)) {
            return true;
        }

        foreach (self::ALLOWED_NON_TENANT_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function describe(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    // ------------------------------------------------------------------

    public function test_every_route_outside_the_allowlist_resolves_a_tenant(): void
    {
        $offenders = [];

        foreach ($this->appRoutes() as $route) {
            if ($this->isAllowlisted($route)) {
                continue;
            }

            if (! in_array(ResolveTenantFromPath::class, $this->middlewareOf($route), true)) {
                $offenders[] = $this->describe($route);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Estas rotas não têm o middleware `tenant` (ResolveTenantFromPath) na pilha:',
            '  '.implode("\n  ", $offenders),
            '',
            'Sem ele o TenantContext fica vazio e o global scope TenantScope não filtra NADA',
            'na requisição — inclusive nas interações Livewire do componente servido por ela.',
            'Mova a rota para dentro do grupo `->middleware(\'tenant\')` ou, se ela realmente',
            'não pertence a nenhum tenant, adicione-a à allowlist deste teste explicando por quê.',
        ]));
    }

    public function test_every_route_outside_the_allowlist_lives_under_the_tenant_prefix(): void
    {
        $offenders = [];

        foreach ($this->appRoutes() as $route) {
            if ($this->isAllowlisted($route)) {
                continue;
            }

            if (! str_starts_with($route->uri(), '{tenant}')) {
                $offenders[] = $this->describe($route);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Estas rotas estão fora do prefixo `{tenant:slug}` e fora da allowlist:',
            '  '.implode("\n  ", $offenders),
            '',
            'O prefixo é a única fonte do tenant nesta aplicação (não há resolução por',
            'subdomínio nem por sessão). Uma rota fora dele não tem como se isolar.',
        ]));
    }

    /**
     * Nenhum componente Livewire da aplicação pode ser servido por rota
     * allowlistada: a allowlist é para endpoints sem tenant, e um componente
     * Livewire sempre volta pelo `POST /livewire/update`, onde só o middleware
     * da rota original o protege.
     */
    public function test_no_allowlisted_route_serves_an_application_livewire_component(): void
    {
        $offenders = [];

        foreach ($this->appRoutes() as $route) {
            if (! $this->isAllowlisted($route)) {
                continue;
            }

            $action = $route->getAction('uses');

            if (is_string($action) && str_starts_with($action, 'App\\Livewire\\')) {
                $offenders[] = $this->describe($route).' → '.$action;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Estas rotas allowlistadas servem componentes Livewire da aplicação:',
            '  '.implode("\n  ", $offenders),
            '',
            'Um componente allowlistado roda sem `tenant` também no POST /livewire/update.',
            'Remova a rota da allowlist e coloque-a sob o grupo `tenant`.',
        ]));
    }

    public function test_every_admin_route_requires_an_admin_session(): void
    {
        $offenders = [];

        foreach ($this->appRoutes() as $route) {
            if (! str_starts_with($route->uri(), '{tenant}/admin')) {
                continue;
            }

            if (in_array($route->uri(), self::ADMIN_URIS_WITHOUT_ADMIN_SESSION, true)) {
                continue;
            }

            if (! in_array(EnsureTenantAdminAuthenticated::class, $this->middlewareOf($route), true)) {
                $offenders[] = $this->describe($route);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Estas rotas de admin não exigem sessão de admin (`tenant.auth`):',
            '  '.implode("\n  ", $offenders),
        ]));
    }

    public function test_every_customer_account_route_runs_the_customer_auth_middleware(): void
    {
        $offenders = [];

        foreach ($this->appRoutes() as $route) {
            if (! str_starts_with($route->uri(), '{tenant}/conta')) {
                continue;
            }

            if (! in_array(TenantCustomerAuth::class, $this->middlewareOf($route), true)) {
                $offenders[] = $this->describe($route);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Estas rotas da área do cliente não rodam `tenant.customer.auth`:',
            '  '.implode("\n  ", $offenders),
            '',
            'Ele exige o guard `customer` (E1.5) e é persistente no Livewire (403 no update).',
        ]));
    }
}

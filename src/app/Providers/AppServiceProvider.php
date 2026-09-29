<?php

namespace App\Providers;

use App\Auth\CustomerSessionGuard;
use App\Auth\TenantAdminGuard;
use App\Http\Middleware\EnsureTenantAdminAuthenticated;
use App\Http\Middleware\ResolveTenantFromPath;
use App\Http\Middleware\TenantCustomerAuth;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `scoped` (e não `singleton`): uma instância por requisição/job.
        // Em Octane o container de request é reciclado, então o tenant de uma
        // requisição não vaza para a próxima do mesmo worker.
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('tenant_session', fn ($app, string $name, array $config) => new TenantAdminGuard(
            $name,
            Auth::createUserProvider($config['provider']),
            $app['session.store'],
            $app['request'],
        ));

        Auth::extend('customer_session', fn ($app, string $name, array $config) => new CustomerSessionGuard(
            $name,
            Auth::createUserProvider($config['provider']),
            fn () => $app['request'],
            $app->make(CustomerAuthService::class),
        ));

        /*
         * ACH-01 / ACH-02 — middleware persistente do Livewire.
         *
         * O `POST /livewire/update` é uma rota própria do Livewire (com o grupo
         * `web` apenas). Ele NÃO re-executa o middleware da rota que renderizou
         * o componente: reconstrói a rota original a partir de `memo.path` do
         * snapshot e re-aplica só os middlewares que constem na allowlist de
         * `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware`
         * (vendor, linhas 14-23 — que traz apenas middlewares de Laravel/
         * Jetstream/Sanctum).
         *
         * Sem o registro abaixo:
         * - `ResolveTenantFromPath` não roda, o `TenantContext` fica VAZIO e o
         *   global scope `TenantScope` não filtra nada em NENHUMA interação do
         *   Livewire (filtrar, paginar, salvar, deletar, mudar status);
         * - `EnsureTenantAdminAuthenticated` não roda, e ações destrutivas de
         *   admin (`Admin\Products\Index::delete()`, `toggleActive()`,
         *   `Admin\Orders\Show::updateStatus()`, `Admin\Tenant\Settings::save()`)
         *   executam sem revalidar a sessão.
         *
         * Só é re-aplicado o que estava na rota ORIGINAL do componente, então
         * componentes públicos do storefront (Cart, Checkout, Home, ProductShow)
         * recebem apenas `tenant` — nunca `tenant.auth`. A ordem da rota
         * original também é preservada (`gatherRouteMiddleware`), logo
         * `ResolveTenantFromPath` roda antes de `EnsureTenantAdminAuthenticated`,
         * que depende do `TenantContext`.
         *
         * Coberto por tests/Feature/Tenancy/LivewirePersistentMiddlewareTest.php.
         *
         * ------------------------------------------------------------------
         * ORDEM
         * ------------------------------------------------------------------
         * A ordem desta lista é irrelevante: ela é só uma ALLOWLIST. O que
         * define a ordem de execução é a pilha da rota original, obtida em
         * `PersistentMiddleware::getApplicablePersistentMiddleware()`
         * (vendor:136-145) via `app('router')->gatherRouteMiddleware($route)`
         * e depois apenas FILTRADA por esta allowlist — o `array_filter`
         * preserva a ordem. Logo `SubstituteBindings` → `ResolveTenantFromPath`
         * → (`EnsureTenantAdminAuthenticated` | `TenantCustomerAuth`), que é a
         * ordem de que ambos dependem (`$request->route('tenant')` já
         * substituído e tenant já resolvido).
         * Asseverado em LivewirePersistentMiddlewareTest::
         * test_only_middleware_of_the_original_route_is_reapplied() e em
         * TenantCustomerSessionRevocationTest::
         * test_customer_auth_runs_after_tenant_resolution_on_the_account_routes().
         *
         * ------------------------------------------------------------------
         * `TenantCustomerAuth` NÃO fecha o buraco sozinho — duas razões
         * ------------------------------------------------------------------
         * 1. (histórico) ele nunca abortava e só anotava
         *    `authenticated_customer` no request. Desde E1.5 ele BLOQUEIA
         *    (403 no update) e a identidade vem do guard `customer`, que lê o
         *    cookie do request real;
         * 2. o middleware persistente roda sobre uma requisição DUPLICADA
         *    (`PersistentMiddleware::makeFakeRequest()`, vendor:101-129, usa
         *    `request()->duplicate()`), então tudo que ele escreve no request
         *    (`$request->merge()`, `$request->attributes->set()`) morre com a
         *    cópia e NÃO é visível via `request()` dentro do componente.
         *
         * Por isso as ações de cliente revalidam a sessão elas mesmas — ver
         * `App\Livewire\Tenant\Concerns\AuthenticatesTenantCustomer`. O registro
         * abaixo permanece porque mantém o contrato do middleware uniforme
         * entre a requisição de página e a de update (e faz a limpeza de cookie
         * inválido acontecer nos dois).
         */
        Livewire::addPersistentMiddleware([
            ResolveTenantFromPath::class,
            EnsureTenantAdminAuthenticated::class,
            TenantCustomerAuth::class,
        ]);
    }
}

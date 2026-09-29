<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant.customer` (E1.5): resolução OPCIONAL do cliente. Nunca bloqueia.
 *
 * Não anota nada no request (o antigo `authenticated_customer` morria em
 * `/livewire/update`); a leitura é sempre `Auth::guard('customer')`. A única
 * função é apagar o cookie de sessão de cliente que o guard recusou
 * (revogado, desativado ou de outro tenant), em páginas públicas.
 */
class ResolveTenantCustomer
{
    public function __construct(
        protected CustomerAuthService $authService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $tenant = TenantContext::resolve()?->get();

        if (
            $tenant instanceof Tenant
            && $request->cookie($this->authService->getCookieName($tenant)) !== null
            && ! Auth::guard('customer')->check()
        ) {
            $response->headers->setCookie($this->authService->deleteCookie($tenant));
        }

        return $response;
    }
}

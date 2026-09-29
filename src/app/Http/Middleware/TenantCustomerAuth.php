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
 * `tenant.customer.auth` (E1.5): EXIGE cliente autenticado pelo guard
 * `customer`. Não autenticado:
 * - GET/página: redirect para a home do tenant com flash de erro (e o cookie
 *   inválido, se houver, é apagado);
 * - `POST /livewire/update` (middleware persistente): 403.
 *
 * Também é persistente no Livewire, então cada interação de componente da
 * área do cliente é revalidada. Quem só quer SABER se há cliente logado (ex.:
 * storefront público) usa `Auth::guard('customer')->check()` — ver
 * {@see ResolveTenantCustomer} para a limpeza de cookie inválido.
 */
class TenantCustomerAuth
{
    public function __construct(
        protected CustomerAuthService $authService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = TenantContext::resolve()?->get();

        // Falha fechando: rota protegida sem tenant não é alcançável.
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        if (Auth::guard('customer')->check()) {
            return $next($request);
        }

        if (request()->hasHeader('X-Livewire')) {
            abort(403, 'Sessão de cliente inválida ou expirada.');
        }

        $response = redirect()
            ->route('tenant.home', ['tenant' => $tenant->slug])
            ->with('error', 'Você precisa fazer login para acessar esta página.');

        if ($request->cookie($this->authService->getCookieName($tenant)) !== null) {
            $response->withCookie($this->authService->deleteCookie($tenant));
        }

        return $response;
    }
}

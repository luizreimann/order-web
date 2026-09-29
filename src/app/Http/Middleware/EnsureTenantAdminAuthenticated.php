<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAdminAuthenticated
{
    /**
     * Garante que:
     * - existe tenant resolvido (senão 404: falha fechando, sem 500)
     * - o guard `tenant_admin` autentica um usuário `tenant_admin` DESTE tenant
     *   (a validação de tenant/role vive em {@see \App\Auth\TenantAdminGuard})
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = TenantContext::resolve()?->get();

        if (! $tenant instanceof Tenant) {
            abort(404, 'Tenant não resolvido para a área administrativa.');
        }

        $user = Auth::guard('tenant_admin')->user();

        if (! $user) {
            // No POST /livewire/update não há navegação: 403 em vez de redirect.
            if ($request->hasHeader('X-Livewire')) {
                abort(403);
            }

            return redirect()->route('tenant.admin.login', [
                'tenant' => $tenant->slug,
            ]);
        }

        // Disponibiliza o admin logado para as views (`$tenantAdmin`).
        $request->attributes->set('tenantAdmin', $user);
        view()->share('tenantAdmin', $user);

        return $next($request);
    }
}

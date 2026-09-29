<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAdminAuthenticated
{
    /**
     * Garante que:
     * - existe tenant resolvido
     * - existe usuário logado na sessão
     * - o usuário pertence ao tenant atual
     * - o usuário tem role válida (admin/staff)
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant $tenant */
        $tenant = app(Tenant::class);

        // ID salvo manualmente na session no login
        $userId = $request->session()->get('tenant_admin_user_id');

        if (! $userId) {
            return redirect()->route('tenant.admin.login', [
                'tenant' => $tenant->slug,
            ]);
        }

        $user = User::query()
            ->where('id', $userId)
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['tenant_admin', 'tenant_staff'])
            ->first();

        if (! $user) {
            // sessão inválida / usuário removido
            $request->session()->forget('tenant_admin_user_id');

            return redirect()->route('tenant.admin.login', [
                'tenant' => $tenant->slug,
            ]);
        }

        // Disponibiliza o admin logado
        $request->attributes->set('tenantAdmin', $user);
        view()->share('tenantAdmin', $user);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenantFromPath
{
    public function __construct(
        protected TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $routeParam = $request->route('tenant');

        if ($routeParam instanceof Tenant) {
            $tenant = $routeParam;
        } else {
            $tenant = Tenant::query()
                ->where('slug', (string) $routeParam)
                ->firstOrFail();
        }

        if (! $tenant->is_active) {
            abort(404);
        }

        // Fonte de verdade do tenant ativo (E1.3). É `scoped` no container,
        // então não vaza entre requisições em Octane.
        $this->context->set($tenant);

        view()->share('currentTenant', $tenant);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class ResolveTenantFromPath
{
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

        if (!$tenant->is_active) {
            abort(404);
        }

        app()->instance(Tenant::class, $tenant);
        view()->share('currentTenant', $tenant);

        return $next($request);
    }
}
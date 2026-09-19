<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CustomerAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantCustomerAuth
{
    public function __construct(
        protected CustomerAuthService $authService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if (!$tenant instanceof Tenant) {
            return $next($request);
        }

        $cookieName = $this->authService->getCookieName($tenant);
        $sessionToken = $request->cookie($cookieName);

        if ($sessionToken) {
            $customer = $this->authService->validateSessionToken($tenant, $sessionToken);

            if ($customer) {
                $request->attributes->set('customer', $customer);
                $request->merge(['authenticated_customer' => $customer]);
            } else {
                $response = $next($request);
                return $response->withCookie($this->authService->deleteCookie($tenant));
            }
        }

        return $next($request);
    }
}

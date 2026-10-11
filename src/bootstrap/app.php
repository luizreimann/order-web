<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {
        $middleware->validateCsrfTokens(except: [
            // Webhooks de gateways: autenticados por assinatura (HMAC), sem sessão/CSRF.
            'webhooks/*',
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenantFromPath::class,
            'tenant.auth' => \App\Http\Middleware\EnsureTenantAdminAuthenticated::class,
            'tenant.customer' => \App\Http\Middleware\ResolveTenantCustomer::class,
            'tenant.customer.auth' => \App\Http\Middleware\TenantCustomerAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

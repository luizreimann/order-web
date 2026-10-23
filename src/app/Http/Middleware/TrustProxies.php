<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Atrás de proxy HTTPS (túnel, Cloudflare, load balancer) o esquema e o host vêm
 * em X-Forwarded-*. Desligado por padrão: só confia nos IPs de TRUSTED_PROXIES
 * ("*" confia em todos). Lido em tempo de requisição porque o callback de
 * withMiddleware() roda antes de o .env ser carregado.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $proxies = (string) config('app.trusted_proxies');

        if ($proxies === '') {
            return null;
        }

        return $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
    }
}

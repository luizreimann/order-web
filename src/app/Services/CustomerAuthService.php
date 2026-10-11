<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomerAuthService
{
    public function login(Tenant $tenant, string $phone): array
    {
        // Mesmo normalizador do checkout e dos formulários (ARC-009): qualquer grafia do
        // número cai no MESMO cliente.
        $phone = Phone::normalizeOrNull($phone);

        if ($phone === null) {
            throw new InvalidArgumentException('Telefone obrigatório para login.');
        }

        $customer = Customer::firstOrCreateByPhone($tenant->id, $phone, [
            'name' => '',
            'email' => '',
            'is_active' => true,
            'meta' => [],
        ]);

        $sessionToken = $this->generateSessionToken();

        $customer->session_token = $sessionToken;
        $customer->last_login_at = now();
        $customer->save();

        return [
            'customer' => $customer,
            'session_token' => $sessionToken,
        ];
    }

    public function logout(Tenant $tenant, string $sessionToken): void
    {
        Customer::query()
            ->forTenant($tenant->id)
            ->where('session_token', $sessionToken)
            ->update([
                'session_token' => null,
            ]);
    }

    public function validateSessionToken(Tenant $tenant, string $sessionToken): ?Customer
    {
        if (empty($sessionToken)) {
            return null;
        }

        return Customer::query()
            ->forTenant($tenant->id)
            ->where('session_token', $sessionToken)
            ->where('is_active', true)
            ->first();
    }

    public function generateSessionToken(): string
    {
        return hash('sha256', Str::random(60).time());
    }

    public function getCookieName(Tenant $tenant): string
    {
        return 'customer_session_'.$tenant->id;
    }

    /**
     * Flag `Secure` dos cookies do cliente. Nunca fixo (ARC-003):
     *
     * 1. `session.secure` (SESSION_SECURE_COOKIE) explícito manda;
     * 2. se indefinido (null), é Secure em produção ou quando a requisição
     *    atual já é HTTPS (respeita proxies confiáveis);
     * 3. caso contrário (local/testing em HTTP) não é Secure.
     */
    public function cookieSecure(): bool
    {
        $configured = config('session.secure');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return app()->environment('production') || request()->isSecure();
    }

    /**
     * Fábrica única de cookies do cliente: path `/`, httpOnly, SameSite=Lax e
     * Secure derivado de {@see cookieSecure()}.
     */
    public function makeCookie(string $name, string $value, int $minutes): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            $name,
            $value,
            $minutes,
            '/',
            null,
            $this->cookieSecure(),
            true,  // httpOnly
            false, // raw
            'lax'  // sameSite
        );
    }

    public function createCookie(Tenant $tenant, string $sessionToken): \Symfony\Component\HttpFoundation\Cookie
    {
        return $this->makeCookie(
            $this->getCookieName($tenant),
            $sessionToken,
            60 * 24 * 90 // 90 dias
        );
    }

    public function getDismissCookieName(Tenant $tenant): string
    {
        return 'login_popup_dismissed_'.$tenant->id;
    }

    public function createDismissCookie(Tenant $tenant): \Symfony\Component\HttpFoundation\Cookie
    {
        return $this->makeCookie(
            $this->getDismissCookieName($tenant),
            '1',
            60 * 24 // 1 dia
        );
    }

    public function deleteCookie(Tenant $tenant): \Symfony\Component\HttpFoundation\Cookie
    {
        // Mesmos atributos da emissão, para o navegador sobrescrever/expirar
        // exatamente o cookie criado (inclusive Secure/SameSite).
        return new \Symfony\Component\HttpFoundation\Cookie(
            $this->getCookieName($tenant),
            null,
            now()->subYears(5), // expira no passado (Symfony espera timestamp/DateTime, não minutos)
            '/',
            null,
            $this->cookieSecure(),
            true,
            false,
            \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX
        );
    }
}

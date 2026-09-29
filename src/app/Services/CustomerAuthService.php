<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Support\Str;

class CustomerAuthService
{
    public function login(Tenant $tenant, string $phone): array
    {
        $phone = $this->normalizePhone($phone);

        $customer = Customer::query()
            ->forTenant($tenant->id)
            ->where('phone', $phone)
            ->first();

        if (! $customer) {
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'phone' => $phone,
                'name' => '',
                'email' => '',
                'is_active' => true,
                'meta' => [],
            ]);
        }

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

    public function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    public function getCookieName(Tenant $tenant): string
    {
        return 'customer_session_'.$tenant->id;
    }

    public function createCookie(Tenant $tenant, string $sessionToken): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            $this->getCookieName($tenant),
            $sessionToken,
            60 * 24 * 90, // 90 dias
            '/',
            null,
            false, // secure = false para funcionar em localhost
            true,  // httpOnly = true
            false, // raw
            'lax'  // sameSite
        );
    }

    public function deleteCookie(Tenant $tenant): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget($this->getCookieName($tenant));
    }
}

<?php

namespace App\Auth;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/**
 * Guard do cliente da loja (E1.4).
 *
 * Valida o cookie `customer_session_{tenant_id}` com
 * {@see CustomerAuthService::validateSessionToken()} (filtra tenant e
 * `is_active`). O tenant vem do {@see TenantContext} (path), nunca do
 * snapshot do Livewire, e o cookie é lido do request REAL — por isso funciona
 * igual em páginas e em `POST /livewire/update`.
 *
 * DECISÃO D3: o fator continua sendo o telefone (o cookie é emitido por
 * `CustomerAuthService::login()` a partir do telefone, sem verificação de
 * posse). Este guard não altera a força do login.
 *
 * Sem cache entre requisições: a instância do guard é reaproveitada (testes,
 * Octane) e a revogação (logout, `is_active=false`) tem de valer na hora. O
 * resultado só é memoizado para o MESMO request + tenant + token.
 */
class CustomerSessionGuard implements Guard
{
    use GuardHelpers;

    private ?Request $memoRequest = null;

    private ?string $memoKey = null;

    /** @var (callable(): Request) */
    private $requestResolver;

    /**
     * @param  callable(): Request  $requestResolver
     */
    public function __construct(
        private readonly string $name,
        UserProvider $provider,
        callable $requestResolver,
        private readonly CustomerAuthService $authService,
    ) {
        $this->provider = $provider;
        $this->requestResolver = $requestResolver;
    }

    public function user()
    {
        $tenant = TenantContext::resolve()?->get();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $request = ($this->requestResolver)();
        $token = $request->cookie($this->authService->getCookieName($tenant));

        if (! is_string($token) || $token === '') {
            $this->forgetUser();

            return null;
        }

        $key = $tenant->getKey().'|'.$token;

        if ($this->user !== null && $this->memoRequest === $request && $this->memoKey === $key) {
            return $this->user;
        }

        $customer = $this->authService->validateSessionToken($tenant, $token);

        $this->user = $customer;
        $this->memoRequest = $request;
        $this->memoKey = $key;

        return $customer;
    }

    /**
     * Sem credenciais a validar: a autenticação acontece via
     * `CustomerAuthService::login()` + cookie, não por este guard.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Descarta o cache do request corrente (chamar após login/logout).
     */
    public function forgetUser(): void
    {
        $this->user = null;
        $this->memoRequest = null;
        $this->memoKey = null;
    }

    public function customer(): ?Customer
    {
        $user = $this->user();

        return $user instanceof Customer ? $user : null;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

<?php

namespace App\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Guard de sessão do admin de tenant (E1.4).
 *
 * Um `SessionGuard` comum guarda o id do usuário numa ÚNICA chave
 * (`login_{guard}_{sha1(classe)}`), então um segundo login no mesmo navegador
 * sobrescreve o primeiro: não dá para estar logado em dois tenants. Aqui o
 * nome da chave é derivado do tenant do {@see TenantContext}
 * (`login_tenant_admin_{tenant_id}_...`), o que permite sessões simultâneas.
 *
 * Regras (falham fechando):
 * - sem tenant no contexto → sem usuário (nunca erro);
 * - o usuário precisa ter role `tenant_admin` E `tenant_id` igual ao do
 *   contexto. `platform_admin` (tenant_id null) e roles desconhecidas
 *   (ex.: o extinto `tenant_staff`) NÃO autenticam aqui;
 * - o usuário em cache é re-validado a cada chamada (tenant e sessão), pois a
 *   instância do guard vive mais que a requisição em testes/Octane.
 */
class TenantAdminGuard extends SessionGuard
{
    private ?string $boundTenantId = null;

    private bool $explicitUser = false;

    public function getName(): string
    {
        $tenantId = TenantContext::resolve()?->id() ?? 'none';

        return 'login_'.$this->name.'_'.$tenantId.'_'.sha1(SessionGuard::class);
    }

    public function user()
    {
        $tenantId = TenantContext::resolve()?->id();

        if ($tenantId === null) {
            return null;
        }

        if (! $this->explicitUser) {
            $cached = $this->user;

            $stale = $this->boundTenantId !== $tenantId
                || ($cached !== null && (string) $this->session->get($this->getName()) !== (string) $cached->getAuthIdentifier());

            if ($stale) {
                $this->user = null;
                $this->loggedOut = false;
            }
        }

        $this->boundTenantId = $tenantId;

        $user = parent::user();

        if ($user !== null && ! $this->belongsToTenant($user, $tenantId)) {
            // Em `actingAs()` (explícito) mantém o usuário para outros tenants.
            if (! $this->explicitUser) {
                $this->user = null;
            }

            return null;
        }

        return $user;
    }

    /**
     * Rejeita (não apenas ignora) login de quem não pode representar este
     * tenant: falha alta em vez de gravar uma sessão inútil.
     */
    public function login(Authenticatable $user, $remember = false)
    {
        $tenantId = TenantContext::resolve()?->id();

        if ($tenantId === null || ! $this->belongsToTenant($user, $tenantId)) {
            throw new \InvalidArgumentException('Usuário não pode autenticar no tenant do contexto.');
        }

        parent::login($user, $remember);

        // `parent::login()` chama `setUser()`, que marca como explícito; aqui
        // o usuário veio de um login real e é re-validado contra a sessão.
        $this->explicitUser = false;
        $this->boundTenantId = $tenantId;
    }

    public function setUser(Authenticatable $user)
    {
        // `actingAs()` de teste: vale para qualquer tenant, mas continua
        // sujeito à validação de tenant/role em `user()`.
        $this->explicitUser = true;

        return parent::setUser($user);
    }

    public function logout()
    {
        $this->explicitUser = false;

        parent::logout();
    }

    private function belongsToTenant(Authenticatable $user, string $tenantId): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // `getRawOriginal` + `tryFrom`: uma role legada/desconhecida
        // (`tenant_staff`) não pode estourar ValueError do cast do enum.
        $role = Role::tryFrom((string) $user->getRawOriginal('role'));

        return $role === Role::TenantAdmin
            && $user->tenant_id !== null
            && (string) $user->tenant_id === $tenantId;
    }
}

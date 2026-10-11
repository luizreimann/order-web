<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Base das policies de recursos de tenant (E1.6 / SEC-004).
 *
 * Regra mínima: o ator é `User` com papel de escopo TENANT que concede a
 * permissão pedida ({@see \App\Enums\Role::can()}) E `users.tenant_id` é igual
 * ao tenant do recurso. `platform_admin` (sem impersonation) NUNCA passa aqui —
 * impersonation é a Sprint 07 e exigirá mudar só este método.
 */
abstract class TenantResourcePolicy
{
    protected function allows(?User $user, Permission $permission, mixed $tenantId): bool
    {
        if (! $user instanceof User || $tenantId === null || $user->tenant_id === null) {
            return false;
        }

        $role = $user->resolvedRole();

        return $role !== null
            && $role->requiresTenant()
            && $role->can($permission)
            && (string) $user->tenant_id === (string) $tenantId;
    }
}

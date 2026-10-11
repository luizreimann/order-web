<?php

namespace App\Enums;

/**
 * Papéis de `users.role` (decisão D2).
 *
 * Duas dimensões: escopo ({@see RoleScope}: plataforma | tenant) e nível
 * (`admin`; futuros `staff`, `owner`). `tenant_staff` foi removido — voltará
 * quando houver matriz de permissões.
 *
 * `platform_admin` NÃO pertence a tenant: `users.tenant_id` é nullable.
 */
enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case TenantAdmin = 'tenant_admin';

    public function scope(): RoleScope
    {
        return match ($this) {
            self::PlatformAdmin => RoleScope::Platform,
            self::TenantAdmin => RoleScope::Tenant,
        };
    }

    public function level(): string
    {
        return match ($this) {
            self::PlatformAdmin, self::TenantAdmin => 'admin',
        };
    }

    public function requiresTenant(): bool
    {
        return $this->scope() === RoleScope::Tenant;
    }

    /**
     * Permissões de TENANT do papel. Único lugar a alterar ao criar um papel.
     *
     * `platform_admin` não tem nenhuma permissão de tenant: sem impersonation
     * (Sprint 07) ele não acessa recursos de tenant.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::TenantAdmin => Permission::cases(),
            self::PlatformAdmin => [],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}

<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;

/**
 * Abilities sensíveis do próprio tenant. Aqui o "tenant do recurso" é
 * `$tenant->id`.
 */
class TenantPolicy extends TenantResourcePolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ViewDashboard, $tenant->id);
    }

    public function manageSettings(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageStoreSettings, $tenant->id);
    }

    /** Troca o SLUG (URL base) da loja. */
    public function updateSlug(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::UpdateStoreSlug, $tenant->id);
    }

    public function managePaymentMethods(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManagePaymentMethods, $tenant->id);
    }

    /** Credenciais de pagamento (Mercado Pago). */
    public function manageIntegrations(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageIntegrations, $tenant->id);
    }

    public function toggleStoreStatus(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ToggleStoreStatus, $tenant->id);
    }
}

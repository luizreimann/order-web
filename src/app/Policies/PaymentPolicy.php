<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;

class PaymentPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ViewPayments, $tenant->id);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allows($user, Permission::ViewPayments, $payment->tenant_id);
    }
}

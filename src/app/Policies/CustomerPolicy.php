<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;

class CustomerPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageCustomers, $tenant->id);
    }

    public function view(User $user, Customer $model): bool
    {
        return $this->allows($user, Permission::ManageCustomers, $model->tenant_id);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageCustomers, $tenant->id);
    }

    public function update(User $user, Customer $model): bool
    {
        return $this->allows($user, Permission::ManageCustomers, $model->tenant_id);
    }

    public function delete(User $user, Customer $model): bool
    {
        return $this->allows($user, Permission::ManageCustomers, $model->tenant_id);
    }
}

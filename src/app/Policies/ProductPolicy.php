<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;

class ProductPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageCatalog, $tenant->id);
    }

    public function view(User $user, Product $model): bool
    {
        return $this->allows($user, Permission::ManageCatalog, $model->tenant_id);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageCatalog, $tenant->id);
    }

    public function update(User $user, Product $model): bool
    {
        return $this->allows($user, Permission::ManageCatalog, $model->tenant_id);
    }

    public function delete(User $user, Product $model): bool
    {
        return $this->allows($user, Permission::ManageCatalog, $model->tenant_id);
    }
}

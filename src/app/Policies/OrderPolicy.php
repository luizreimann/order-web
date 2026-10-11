<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;

class OrderPolicy extends TenantResourcePolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->allows($user, Permission::ManageOrders, $tenant->id);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::ManageOrders, $order->tenant_id);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->allows($user, Permission::ManageOrders, $order->tenant_id);
    }
}

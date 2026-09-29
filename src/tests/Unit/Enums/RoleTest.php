<?php

namespace Tests\Unit\Enums;

use App\Enums\Role;
use App\Enums\RoleScope;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_roles_carry_the_two_dimensions_of_d2(): void
    {
        $this->assertSame(RoleScope::Platform, Role::PlatformAdmin->scope());
        $this->assertSame(RoleScope::Tenant, Role::TenantAdmin->scope());
        $this->assertSame('admin', Role::PlatformAdmin->level());
        $this->assertSame('admin', Role::TenantAdmin->level());
    }

    public function test_only_tenant_roles_require_a_tenant(): void
    {
        $this->assertFalse(Role::PlatformAdmin->requiresTenant());
        $this->assertTrue(Role::TenantAdmin->requiresTenant());
    }

    public function test_tenant_staff_is_not_a_role_anymore(): void
    {
        $legacy = 'tenant_'.'staff';
        $this->assertNotContains($legacy, array_map(fn (Role $r) => $r->value, Role::cases()));
        $this->assertSame(['platform_admin', 'tenant_admin'], array_map(fn (Role $r) => $r->value, Role::cases()));
    }
}

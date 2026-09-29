<?php

namespace App\Enums;

/**
 * Dimensão "escopo" do papel (decisão D2): a quem o papel pertence.
 */
enum RoleScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
}

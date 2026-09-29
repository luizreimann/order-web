<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca um model como isolado por tenant (E1.1).
 *
 * A implementação vem inteira de {@see BelongsToTenant}; a interface existe
 * para dar um tipo aos pontos que precisam falar de "um model escopado por
 * tenant" (e para deixar a lista de models escopados greppável):
 *
 * ```
 * grep -rn "implements TenantScoped" app/Models
 * ```
 *
 * @phpstan-require-extends Model
 */
interface TenantScoped
{
    public static function tenantColumn(): string;

    /**
     * O tipo concreto é `Builder<static>` — ver {@see BelongsToTenant}. Aqui
     * fica `Builder<Model>` porque a interface não é subtipo de Model.
     *
     * @return Builder<Model>
     */
    public static function withoutTenantScope(): Builder;
}

<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope de isolamento por tenant (E1.1).
 *
 * Aplicado pela trait {@see \App\Models\Concerns\BelongsToTenant}. Filtra por
 * `tenant_id` SOMENTE quando existe tenant no {@see TenantContext}; nos demais
 * contextos (plataforma, indefinido) não adiciona cláusula alguma.
 *
 * Para remover o scope use `Model::withoutTenantScope()` — nunca
 * `withoutGlobalScopes()`, que derrubaria outros scopes junto.
 *
 * ## Alcance: este scope é só a barreira de LEITURA
 *
 * Só afeta consultas construídas pelo query builder do Eloquent. A escrita por instância e a
 * re-hidratação de propriedades públicas do Livewire NÃO passam por aqui (usam `newModelQuery()` e
 * `newQueryForRestoration()`), e por isso a trait {@see \App\Models\Concerns\BelongsToTenant}
 * tem barreiras próprias para esses dois caminhos: `assertWritableInCurrentTenant()` e a
 * sobrescrita de `newQueryForRestoration()`. SQL cru (`DB::table()`) segue fora de qualquer barreira
 * e cada uso em `app/` está justificado em `TenantIsolationAuditTest`. Racional completo na trait.
 */
class TenantScope implements Scope
{
    public function __construct(
        protected string $column = 'tenant_id',
    ) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = TenantContext::resolve();

        if ($context === null || ! $context->hasTenant()) {
            return;
        }

        // Coluna qualificada (`products.tenant_id`) para não ficar ambígua em
        // joins — relações belongsToMany com os pivôs `category_product` e
        // `showcase_product` passam por aqui.
        $builder->where($model->qualifyColumn($this->column), $context->id());
    }
}

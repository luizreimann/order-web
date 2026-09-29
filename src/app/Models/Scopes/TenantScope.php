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
 * ## LIMITE DE ALCANCE — isto é um filtro de LEITURA, não autorização
 *
 * Só afeta consultas construídas pelo query builder do Eloquent. NÃO alcança:
 *
 * - **escrita por instância**: `save()`/`update()`/`delete()` de instância usam
 *   `newModelQuery()` (`vendor/laravel/framework/src/Illuminate/Database/
 *   Eloquent/Model.php:1219`, `:1578`, `:1608`), que não registra global scopes
 *   — ao contrário de `newQuery()` (`Model.php:1622`);
 * - **propriedades públicas de Livewire**: são re-hidratadas do snapshot por
 *   `newQueryForRestoration()` → `newQueryWithoutScopes()`
 *   (`Model.php:1669-1672`), chamado em
 *   `vendor/livewire/livewire/src/Features/SupportModels/ModelSynth.php:65` e
 *   `.../SupportLegacyModels/EloquentModelSynth.php:207`. Ali a única barreira
 *   é o checksum HMAC do snapshot, que não expira nem acompanha revogação de
 *   sessão;
 * - **SQL cru** (`DB::table()`, `DB::statement()`).
 *
 * Consequência prática: um model de outro tenant obtido por qualquer uma dessas
 * vias grava com `save()` sem nenhuma barreira. Racional completo e checklist
 * em {@see \App\Models\Concerns\BelongsToTenant}.
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

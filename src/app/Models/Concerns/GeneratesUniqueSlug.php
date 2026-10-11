<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Geração GARANTIDA de `slug` único por tenant (E2.4 — DB-004).
 *
 * A coluna `slug` é `NOT NULL` e a unique `(tenant_id, slug)` (parcial, só
 * registros ativos — E2.5) vale para toda linha. Para que isso não vire erro 500
 * quando o admin cria dois itens com o mesmo nome ("Bolo" e "Bolo"), o
 * `saving` preenche o slug quando ele vem vazio, a partir do `name`, e
 * desambigua com sufixo numérico (`bolo`, `bolo-2`, `bolo-3`...).
 *
 * Regras:
 *  - slug informado explicitamente NUNCA é alterado: se colidir, o banco rejeita
 *    (e os formulários validam antes com `Rule::unique`);
 *  - registros em soft delete não contam (o índice único é parcial);
 *  - nome sem caracteres "sluggáveis" (ex.: "!!!") cai num slug base derivado
 *    do tipo do model (`product`, `category`, `showcase`).
 *
 * Limite conhecido: a checagem é "ler e depois gravar" — duas requisições
 * simultâneas gerando o mesmo slug podem colidir; nesse caso a unique do banco
 * barra a segunda (nunca há duplicata silenciosa).
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements TenantScoped
 */
trait GeneratesUniqueSlug
{
    public static function bootGeneratesUniqueSlug(): void
    {
        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            if (blank($model->getAttribute('slug')) && filled($model->getAttribute('name'))) {
                $model->setAttribute('slug', $model->makeUniqueSlug());
            }
        });
    }

    protected function makeUniqueSlug(): string
    {
        $base = Str::slug((string) $this->getAttribute('name'));
        if ($base === '') {
            $base = Str::slug(class_basename($this));
        }

        $tenantColumn = static::tenantColumn();
        $tenantId = $this->getAttribute($tenantColumn) ?? TenantContext::resolve()?->id();

        if ($tenantId === null) {
            // Sem tenant não há como checar colisão; o insert falhará por FK/NOT NULL.
            return $base;
        }

        // O scope de soft delete permanece (registros excluídos não contam); o de tenant
        // é removido porque o filtro é explícito logo abaixo, pelo tenant do PRÓPRIO model.
        $query = static::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where($tenantColumn, $tenantId)
            ->where(function ($q) use ($base) {
                $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            });

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        $taken = array_flip($query->pluck('slug')->all());

        if (! isset($taken[$base])) {
            return $base;
        }

        for ($n = 2; isset($taken["{$base}-{$n}"]); $n++);

        return "{$base}-{$n}";
    }
}

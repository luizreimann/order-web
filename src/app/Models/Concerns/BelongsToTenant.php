<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento de tenant por padrão (E1.1 — SEC-003 / ARC-002).
 *
 * Antes desta trait o isolamento era **opt-in**: dependia de o desenvolvedor
 * lembrar de `->forTenant($id)` em ~41 pontos, e um único `Model::query()`
 * esquecido vazava dados entre lojas. Com a trait o isolamento passa a ser
 * **opt-out**: filtra sozinho, e quem precisa atravessar tenants tem de dizer
 * isso explicitamente via {@see self::withoutTenantScope()}.
 *
 * O que a trait faz:
 *
 * 1. registra o global scope {@see TenantScope};
 * 2. preenche `tenant_id` no `creating` quando o atributo não veio e há
 *    tenant no contexto;
 * 3. expõe o opt-out explícito `Model::withoutTenantScope()`;
 * 4. expõe a relação `tenant()` (models que já a definem continuam com a
 *    própria — método de classe tem precedência sobre método de trait).
 *
 * ## Comportamento por contexto
 *
 * | Contexto do {@see TenantContext}      | Filtro          | Auto-fill |
 * |---------------------------------------|-----------------|-----------|
 * | tenant definido (`set`/`runFor`)      | `tenant_id = X` | sim       |
 * | plataforma (`usePlatformScope`)       | nenhum          | não       |
 * | indefinido (job, seeder, webhook)     | nenhum          | não       |
 *
 * ## Por que a ausência de contexto NÃO é erro
 *
 * Lançar exceção sem contexto seria o comportamento "fecha por padrão" mais
 * agressivo, mas quebraria de uma vez: o webhook de pagamento (que roda fora
 * do prefixo `/{tenant:slug}` e por definição não tem tenant), os seeders
 * (`CarolDocesDemoSeeder` cria dados com `tenant_id` explícito, fora de
 * request), comandos artisan, jobs em fila e o futuro painel de plataforma
 * (D2). A ausência de contexto é, portanto, um estado **legítimo** — e não
 * um erro a ser detectado em runtime.
 *
 * O que o filtro automático dá é cobertura de LEITURA sempre que existe
 * contexto: nos caminhos HTTP de tenant o contexto é sempre populado por
 * `ResolveTenantFromPath` (inclusive no `POST /livewire/update`, via
 * `Livewire::addPersistentMiddleware()`). Fora deles não há "outro tenant" de
 * quem se isolar, porque não há tenant corrente algum.
 *
 * A distinção entre "plataforma" e "indefinido" existe justamente para
 * permitir endurecer isso depois sem refatoração transversal: basta o
 * TenantScope passar a exigir modo explícito, e o painel de plataforma já
 * estará marcado corretamente.
 *
 * ## LIMITES — o que esta trait NÃO faz (leia antes de confiar nela)
 *
 * O {@see TenantScope} é um filtro de **leitura via query builder**, e nada
 * mais. Ele NÃO é uma barreira de autorização. Concretamente:
 *
 * 1. **Não protege escrita por instância.** `$model->save()`, `->update()` e
 *    `->delete()` de instância não passam pelo global scope: usam
 *    `newModelQuery()`, que é explicitamente "sem global scopes".
 *    Evidência (Laravel 12, verifique você mesmo):
 *    - `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:1219`
 *      — `save()` monta o update com `$query = $this->newModelQuery();`
 *    - `Model.php:1578` — `performDeleteOnModel()` faz
 *      `$this->setKeysForSaveQuery($this->newModelQuery())->delete();`
 *    - `Model.php:1608-1614` — `newModelQuery()` constrói o builder sem
 *      registrar global scope algum (compare com `newQuery()`, `Model.php:1622`,
 *      que chama `registerGlobalScopes()`).
 *    Ou seja: obter um model de OUTRO tenant por qualquer via e chamar `save()`
 *    escreve na linha dele **sem nenhuma barreira**.
 *
 * 2. **Não protege propriedades públicas de Livewire.** Um
 *    `public Product $product` é re-hidratado do snapshot a cada
 *    `POST /livewire/update` com uma query SEM global scopes:
 *    - `vendor/livewire/livewire/src/Features/SupportModels/ModelSynth.php:65`
 *      — `(new $class)->newQueryForRestoration($key)->useWritePdo()->firstOrFail()`
 *    - `vendor/livewire/livewire/src/Features/SupportLegacyModels/EloquentModelSynth.php:207`
 *      — `$model->newQueryForRestoration($meta['key'])`
 *    - `Model.php:1669-1672` — `newQueryForRestoration()` é
 *      `newQueryWithoutScopes()->whereKey($ids)`
 *    Nesse caminho a ÚNICA barreira é o checksum HMAC do snapshot (assinado com
 *    a `APP_KEY`). Esse checksum garante que os dados não foram adulterados —
 *    e mais nada: ele não expira, não acompanha logout, não acompanha
 *    expiração de cookie e não acompanha `is_active = false`. Um payload de
 *    update capturado permanece válido indefinidamente.
 *
 * 3. **Não é autorização.** "Este registro pertence ao tenant corrente" é
 *    diferente de "este ator pode escrever neste registro". Quem escreve tem de
 *    checar o ator na própria ação — ver
 *    {@see \App\Livewire\Tenant\Concerns\AuthenticatesTenantCustomer} e
 *    `EnsureTenantAdminAuthenticated`.
 *
 * Resumo operacional: trate o global scope como *defense in depth* contra
 * consulta esquecida, nunca como prova de isolamento. Toda escrita precisa de
 * uma checagem própria e explícita.
 *
 * ## Auditoria
 *
 * Todo acesso cross-tenant é greppável:
 *
 * ```
 * grep -rn "withoutTenantScope\|runForPlatform\|usePlatformScope" app/
 * ```
 *
 * A trait só faz sentido dentro de um Eloquent Model — ela chama
 * `static::addGlobalScope()`, `static::creating()` e `static::query()`. As duas
 * anotações abaixo declaram esse contrato: `@mixin` para os analisadores de
 * editor (que de outro modo reportam "undefined method", por analisarem a trait
 * isolada da classe que a usa) e `@phpstan-require-extends` para o PHPStan
 * verificar que quem usa a trait realmente estende Model.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope(static::tenantColumn()));

        static::creating(function (Model $model): void {
            $column = static::tenantColumn();

            if ($model->getAttribute($column) !== null) {
                return;
            }

            $tenantId = TenantContext::resolve()?->id();

            if ($tenantId !== null) {
                $model->setAttribute($column, $tenantId);
            }
        });
    }

    /**
     * Coluna que guarda o tenant. Sobrescreva no model se algum dia divergir.
     */
    public static function tenantColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * Opt-out EXPLÍCITO do isolamento por tenant.
     *
     * Use só quando a operação é legitimamente cross-tenant (hoje: lookup de
     * pedido/pagamento pelo id do gateway no webhook, que chega sem tenant no
     * path). Toda chamada é um ponto de auditoria.
     *
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, static::tenantColumn());
    }
}

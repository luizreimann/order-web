<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
 * | Contexto do {@see TenantContext}      | Leitura         | Escrita              | Auto-fill |
 * |---------------------------------------|-----------------|----------------------|-----------|
 * | tenant definido (`set`/`runFor`)      | `tenant_id = X` | só linhas do tenant X | sim       |
 * | plataforma (`usePlatformScope`)       | nenhum          | livre                | não       |
 * | indefinido (job, seeder, webhook)     | nenhum          | livre                | não       |
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
 * ## As três barreiras (e o que continua fora delas)
 *
 * O {@see TenantScope} sozinho é só um filtro de **leitura via query builder**. Por isso a trait
 * acrescenta duas barreiras, e o conjunto é o que sustenta o isolamento:
 *
 * 1. **Leitura:** o global scope filtra por `tenant_id` quando há tenant no contexto.
 * 2. **Escrita por instância:** `save()`, `update()`, `delete()`, `restore()` e `forceDelete()` de
 *    instância usam `newModelQuery()` (sem global scopes: `Model.php:1219`, `:1578`, `:1608`), então
 *    o escopo não os alcança. Com um tenant no contexto, `assertWritableInCurrentTenant()` (eventos
 *    `saving`, `deleting` e `restoring`) lança {@see CrossTenantWriteException} se a linha — atual OU
 *    original — não é do tenant do contexto. Cobre também mover uma linha para outro tenant e criar
 *    já em outro tenant com `tenant_id` explícito.
 * 3. **Re-hidratação do Livewire:** `public Model $x` volta do snapshot por `newQueryForRestoration()`,
 *    que por padrão é `newQueryWithoutScopes()` (`Model.php:1669-1672`; usado em
 *    `ModelSynth.php:65`). A trait sobrescreve esse método para restaurar PELO escopo (e pelo soft
 *    delete): outro tenant, ou registro apagado, não é encontrado (404). Vale mesmo com checksum válido.
 *
 * O que continua fora, de propósito e provado por `TenantIsolationAuditTest` (listas fechadas):
 *
 * - **Sem tenant no contexto** (plataforma, job, seeder, comando, webhook) nada é filtrado nem
 *   barrado: não há "outro tenant" de quem se isolar. Quem roda assim usa `runFor()` quando grava;
 * - **SQL cru** (`DB::table()`...) ignora tudo: cada uso em `app/` está justificado na auditoria;
 * - **opt-outs explícitos** (`withoutTenantScope()`): cada um está justificado na auditoria;
 * - **Autorização não é isolamento:** "este registro é do tenant corrente" é diferente de "este ator
 *   pode escrever nele". Cada action ainda checa o ator (policies, `AuthenticatesTenantCustomer`,
 *   `EnsureTenantAdminAuthenticated`).
 *
 * Provas: `TenantWriteIsolationTest` (escrita e re-hidratação), `ForgedSnapshotAcrossTenantsTest`
 * (snapshot assinado de outro tenant) e `TenantIsolationAuditTest` (model novo sem a trait, opt-out
 * novo e SQL cru novo derrubam o teste).
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

        // Escrita por instância: o scope só filtra LEITURA, então a barreira de escrita é esta. Com um
        // tenant no contexto, nenhuma linha de outro tenant é gravada, apagada, restaurada, nem movida.
        foreach (['saving', 'deleting', 'restoring'] as $event) {
            static::registerModelEvent($event, fn (Model $model) => static::assertWritableInCurrentTenant($model));
        }
    }

    /**
     * @throws CrossTenantWriteException a linha (atual ou original) não é do tenant do contexto
     */
    protected static function assertWritableInCurrentTenant(Model $model): void
    {
        $context = TenantContext::resolve();

        // Sem tenant no contexto (plataforma, job, seeder, webhook) não há "outro tenant" de quem se isolar.
        if ($context === null || ! $context->hasTenant()) {
            return;
        }

        $column = static::tenantColumn();
        $current = (string) $model->getAttribute($column);
        $original = $model->exists ? (string) $model->getOriginal($column) : $current;

        foreach ([$current, $original] as $rowTenantId) {
            // Vazio = linha nova cujo tenant o `creating` ainda vai preencher com o do contexto.
            if ($rowTenantId !== '' && $rowTenantId !== (string) $context->id()) {
                throw CrossTenantWriteException::for($model::class, (string) $context->id(), $rowTenantId);
            }
        }
    }

    /**
     * O Livewire re-hidrata `public Model $x` com `newQueryForRestoration()`, que por padrão é
     * `newQueryWithoutScopes()`: o snapshot de um registro de outro tenant voltaria à vida ignorando o
     * escopo. Aqui a restauração passa pelo escopo (e por soft delete): outro tenant, ou registro
     * apagado, não é encontrado. Sem tenant no contexto (jobs em fila) o escopo não filtra, como antes.
     *
     * @param  array<int, mixed>|int|string  $ids
     * @return Builder<static>
     */
    public function newQueryForRestoration($ids)
    {
        return $this->newQuery()->whereKey($ids);
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
     * Amarra um pivô `belongsToMany` ao tenant DESTE model (E2.2): `tenant_id` do
     * pivô é gravado em attach/sync/save e usado como filtro nas leituras, e a FK
     * composta do banco garante que a outra ponta é do mesmo tenant.
     *
     * Em instância sem tenant (as que o Eloquent cria para eager loading) a
     * relação é devolvida intacta: lá o filtro vem do global scope do model
     * relacionado, e não há attach.
     *
     * @template TRelated of Model
     *
     * @param  BelongsToMany<TRelated, $this>  $relation
     * @return BelongsToMany<TRelated, $this>
     */
    protected function withTenantPivot(BelongsToMany $relation): BelongsToMany
    {
        /** @var string|null $tenantId */
        $tenantId = $this->getAttribute(static::tenantColumn());

        return $tenantId === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $tenantId);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, static::tenantColumn());
    }
}

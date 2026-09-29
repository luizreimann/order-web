<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Container\Container;

/**
 * Contexto de tenant da requisição/execução corrente (E1.3).
 *
 * Substitui o `app()->instance(Tenant::class, $tenant)` como fonte de verdade
 * do tenant ativo. É registrado como **scoped** no container
 * (ver `AppServiceProvider::register()`), o que significa:
 *
 * - em FPM: uma instância por requisição (idêntico a singleton);
 * - em Octane: uma instância por requisição, e NÃO compartilhada entre
 *   requisições do mesmo worker — que é o vazamento que o singleton causava;
 * - em jobs/comandos: instância limpa, sem tenant, até que alguém chame
 *   {@see self::set()} ou {@see self::runFor()}.
 *
 * ## Os três contextos (decisão D2 de `.sprint/06-refactor/04-decisions.md`)
 *
 * | Contexto              | Estado                            | Global scope    |
 * |-----------------------|-----------------------------------|-----------------|
 * | Request de tenant     | `set($tenant)`                    | filtra          |
 * | Admin de plataforma   | `usePlatformScope()`              | não filtra      |
 * | Impersonation         | `runFor($tenant, $fn)`            | filtra (assume) |
 *
 * Existe ainda o estado **indefinido** (nenhum dos três): nenhum tenant e
 * nenhum modo de plataforma. É o estado de jobs, comandos artisan, seeders e
 * do webhook de pagamento. Nesse estado o global scope **não filtra** — ver o
 * racional completo em {@see \App\Models\Concerns\BelongsToTenant}.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    protected ?string $tenantId = null;

    protected bool $platform = false;

    /**
     * Resolve o contexto a partir do container, sem estourar quando o
     * container ainda não conhece a binding (ex.: testes unitários puros,
     * boot muito precoce de model).
     */
    public static function resolve(): ?self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            return null;
        }

        return $container->make(self::class);
    }

    /**
     * Define o tenant ativo. Aceita o model ou só o id (útil em jobs, onde
     * serializar o id é mais barato que o model).
     */
    public function set(Tenant|string $tenant): static
    {
        if ($tenant instanceof Tenant) {
            $this->tenant = $tenant;
            $this->tenantId = (string) $tenant->getKey();
        } else {
            $this->tenant = null;
            $this->tenantId = $tenant;
        }

        $this->platform = false;

        return $this;
    }

    /**
     * Tenant ativo. Se o contexto foi definido só com o id, o model é
     * carregado sob demanda (e memoizado).
     */
    public function get(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::query()->whereKey($this->tenantId)->first();
        }

        return $this->tenant;
    }

    /**
     * Id do tenant ativo, sem tocar o banco. É o que o global scope usa.
     */
    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Limpa o contexto: volta ao estado indefinido (sem tenant, sem
     * plataforma).
     */
    public function forget(): static
    {
        $this->tenant = null;
        $this->tenantId = null;
        $this->platform = false;

        return $this;
    }

    /**
     * Entra no modo "admin de plataforma": consulta ATRAVÉS dos tenants.
     *
     * Funcionalmente o global scope se comporta como no estado indefinido
     * (não filtra), mas o modo é explícito — dá para auditar por grep, dá
     * para exibir na UI ("você está vendo todos os tenants") e dá para,
     * numa sprint futura, transformar o estado indefinido em erro sem
     * quebrar o painel de plataforma.
     */
    public function usePlatformScope(): static
    {
        $this->tenant = null;
        $this->tenantId = null;
        $this->platform = true;

        return $this;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    /**
     * Estado serializável do contexto, para salvar/restaurar.
     *
     * @return array{tenant: Tenant|null, tenant_id: string|null, platform: bool}
     */
    public function snapshot(): array
    {
        return [
            'tenant' => $this->tenant,
            'tenant_id' => $this->tenantId,
            'platform' => $this->platform,
        ];
    }

    /**
     * @param  array{tenant: Tenant|null, tenant_id: string|null, platform: bool}  $state
     */
    public function restore(array $state): static
    {
        $this->tenant = $state['tenant'];
        $this->tenantId = $state['tenant_id'];
        $this->platform = $state['platform'];

        return $this;
    }

    /**
     * Executa a closure no contexto do tenant informado e SEMPRE restaura o
     * contexto anterior — inclusive quando a closure lança exceção.
     *
     * Base de impersonation (admin de plataforma assumindo um tenant), de
     * jobs (que recebem o id e se auto-escopam) e de comandos artisan que
     * varrem tenants em loop.
     *
     * `runFor(null, $fn)` executa no estado indefinido (sem filtro).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant|string|null $tenant, callable $callback): mixed
    {
        $previous = $this->snapshot();

        try {
            $tenant === null ? $this->forget() : $this->set($tenant);

            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * Idem {@see self::runFor()}, mas no modo de plataforma.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runForPlatform(callable $callback): mixed
    {
        $previous = $this->snapshot();

        try {
            $this->usePlatformScope();

            return $callback();
        } finally {
            $this->restore($previous);
        }
    }
}

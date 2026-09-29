<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Contador sequencial por tenant (tabela `tenant_counters`).
 *
 * ATENÇÃO: a tabela usa chave primária COMPOSTA (tenant_id, key) e o Eloquent
 * NÃO suporta chaves compostas. Por isso este model NÃO deve ser persistido
 * através de `save()` / `update()` / `delete()` de instância: sem uma chave
 * primária simples o Eloquent gera comandos SEM cláusula WHERE, que afetam
 * TODAS as linhas da tabela e corrompem os contadores de outros tenants
 * (BUG-002).
 *
 * Para incrementar um contador use {@see TenantCounter::nextValue()}; para
 * qualquer outra escrita use o query builder com os dois filtros explícitos:
 * `->where('tenant_id', $id)->where('key', $key)`.
 */
class TenantCounter extends Model
{
    protected $table = 'tenant_counters';

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Incrementa (criando se necessário) o contador do par (tenant_id, key)
     * e devolve o novo valor.
     *
     * A linha é travada com `lockForUpdate()` dentro de uma transação para
     * serializar requisições concorrentes do mesmo tenant. A criação usa
     * `insertOrIgnore` (ON CONFLICT DO NOTHING) para que duas requisições
     * simultâneas do primeiro pedido de um tenant não estourem a PK.
     */
    public static function nextValue(string $tenantId, string $key, int $attempts = 3): int
    {
        return DB::transaction(function () use ($tenantId, $key) {
            $now = now();

            static::query()->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $current = (int) static::queryFor($tenantId, $key)
                ->lockForUpdate()
                ->value('value');

            $next = $current + 1;

            static::queryFor($tenantId, $key)->update([
                'value' => $next,
                'updated_at' => $now,
            ]);

            return $next;
        }, $attempts);
    }

    /**
     * Query builder sempre escopado pela chave composta completa.
     */
    public static function queryFor(string $tenantId, string $key)
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key);
    }
}

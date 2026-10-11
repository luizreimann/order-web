<?php

namespace Tests\Feature\Database\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Helpers de introspecção e de asserção de erro SQL para os testes de
 * integridade de schema (E2.x). Só faz sentido em PostgreSQL.
 *
 * @mixin TestCase
 *
 * @phpstan-require-extends TestCase
 */
trait InteractsWithSchema
{
    public const SQLSTATE_FK_VIOLATION = '23503';

    public const SQLSTATE_NOT_NULL_VIOLATION = '23502';

    public const SQLSTATE_UNIQUE_VIOLATION = '23505';

    public const SQLSTATE_CHECK_VIOLATION = '23514';

    /**
     * Executa o callback dentro de um SAVEPOINT (a transação do RefreshDatabase
     * fica abortada após um erro de constraint no PG) e exige o SQLSTATE dado.
     */
    protected function assertSqlState(string $expected, callable $callback, string $message = ''): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException $e) {
            $this->assertSame($expected, $e->errorInfo[0] ?? null, $message.' — '.$e->getMessage());

            return;
        }

        $this->fail($message !== '' ? $message : "Esperava SQLSTATE {$expected}, mas nenhum erro foi lançado.");
    }

    /**
     * @return array<string, string> `tabela(col1,col2)` => `tabela_referenciada:acao`
     *                               onde acao = a(no action) r(restrict) c(cascade) n(set null)
     */
    protected function foreignKeys(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl,
                   (SELECT string_agg(att.attname, ',' ORDER BY k.ord)
                      FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute att ON att.attrelid = c.conrelid AND att.attnum = k.attnum) AS cols,
                   c.confrelid::regclass::text AS ref,
                   c.confdeltype::text AS del
              FROM pg_constraint c
             WHERE c.contype = 'f'
               AND c.connamespace = current_schema()::regnamespace
        SQL);

        $out = [];
        foreach ($rows as $r) {
            $out["{$r->tbl}({$r->cols})"] = "{$r->ref}:{$r->del}";
        }

        return $out;
    }

    protected function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT data_type, is_nullable FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $row?->data_type;
    }

    protected function columnIsNullable(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT is_nullable FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $row?->is_nullable === 'YES';
    }
}

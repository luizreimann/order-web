<?php

namespace App\Exceptions;

use LogicException;

/**
 * Tentativa de gravar, apagar, restaurar ou mover para outro tenant uma linha que não é do tenant
 * do contexto corrente. É defesa em profundidade: o caminho normal nunca chega aqui (as leituras
 * já são filtradas e as policies já negam). Se isto for lançado, um model de outro tenant entrou em
 * memória por um caminho que ignorou o escopo, e isso é um bug a investigar, não um erro do usuário.
 */
class CrossTenantWriteException extends LogicException
{
    public static function for(string $model, string $contextTenantId, string $rowTenantId): self
    {
        return new self("Escrita cross-tenant recusada em {$model}: o contexto é o tenant {$contextTenantId}, a linha é do tenant {$rowTenantId}.");
    }
}

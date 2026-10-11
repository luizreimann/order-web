<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Estoque insuficiente de um produto com `track_inventory = true` (E2.7).
 *
 * Lançada dentro da transação do pedido: quem captura pode ter certeza de que nada
 * foi gravado (pedido, itens, cliente, número do pedido e baixa de estoque voltam juntos).
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $productId,
        public readonly string $productName,
        public readonly int $requested,
        public readonly int $available,
    ) {
        $message = $available <= 0
            ? "\"{$productName}\" está esgotado."
            : "Só temos {$available} unidade(s) de \"{$productName}\" disponíveis (você pediu {$requested}).";

        parent::__construct($message);
    }
}

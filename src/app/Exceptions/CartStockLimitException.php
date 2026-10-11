<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operação do carrinho recusada pelo limite de estoque (produto com `track_inventory`).
 *
 * É checagem de UX, sem lock: a decisão definitiva é do `OrderService::createFromCart`
 * ({@see InsufficientStockException}). A mensagem já é apresentável ao cliente.
 */
class CartStockLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $productId,
        public readonly int $available,
    ) {
        parent::__construct($message);
    }
}

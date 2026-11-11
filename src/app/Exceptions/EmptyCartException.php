<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O carrinho não tem nada para comprar (vazio, ou só produtos inexistentes, inativos, de outro
 * tenant ou com quantidade zero). Lançada ANTES de qualquer escrita: nenhum cliente, pedido ou
 * número de pedido é gravado.
 */
class EmptyCartException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Seu carrinho está vazio.');
    }
}

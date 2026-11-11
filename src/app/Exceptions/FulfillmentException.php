<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Escolha de retirada/entrega inválida (tipo não oferecido, bairro inexistente, endereço
 * incompleto). `errors` mapeia campo => mensagem, para o formulário marcar cada campo.
 */
class FulfillmentException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public static function field(string $field, string $message): self
    {
        return new self($message, [$field => $message]);
    }
}

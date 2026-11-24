<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dia ou opção de agendamento que a loja não aceita. `errors` mapeia campo => mensagem,
 * para o formulário marcar `scheduled_on` ou `schedule_option`. Sobe da transação do
 * pedido e desfaz o que ela escreveu.
 */
class SchedulingException extends RuntimeException
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

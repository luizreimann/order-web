<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valor em reais digitado (pt-BR) que {@see Money::toCents()} aceita. Use com `required`/`nullable`
 * (valores vazios nem chegam aqui: regras que não são "implícitas" são puladas).
 */
class ValidMoney implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! (is_string($value) || is_int($value) || is_float($value)) || ! Money::isValid($value)) {
            $fail('Valor inválido. Use o formato 19,90 ou 1.250,00.');
        }
    }
}

<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Telefone válido segundo {@see Phone::isValid()}. Valores vazios são ignorados
 * (combine com `required`/`nullable`).
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Phone::isValid($value)) {
            $fail('Informe um telefone válido com DDD (ex.: (11) 99999-9999).');
        }
    }
}

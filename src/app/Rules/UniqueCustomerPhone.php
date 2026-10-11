<?php

namespace App\Rules;

use App\Models\Customer;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Telefone único por tenant, comparado no formato CANÔNICO ({@see Phone::normalize()}):
 * `(11) 99999-9999` conflita com `11999999999`. Espelha a unique parcial do banco
 * (`WHERE deleted_at IS NULL`): clientes excluídos não contam (o SoftDeletes do model
 * já os filtra).
 */
class UniqueCustomerPhone implements ValidationRule
{
    public function __construct(
        private readonly string $tenantId,
        private readonly ?string $ignoreCustomerId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $phone = Phone::normalizeOrNull(is_string($value) ? $value : null);

        if ($phone === null) {
            return;
        }

        $exists = Customer::query()
            ->forTenant($this->tenantId)
            ->where('phone', $phone)
            ->when($this->ignoreCustomerId !== null, fn ($q) => $q->whereKeyNot($this->ignoreCustomerId))
            ->exists();

        if ($exists) {
            $fail('Este telefone já está cadastrado.');
        }
    }
}

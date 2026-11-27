<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Código de login por WhatsApp. Só o HMAC fica no banco; o código em claro não é coluna.
 */
class CustomerLoginCode extends Model implements TenantScoped
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'phone',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
        'idempotency_key',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Mesmo canônico de `customers.phone`, para a busca por loja e telefone não
     * depender da grafia que chegou no pedido.
     */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Phone::normalizeOrNull($value);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}

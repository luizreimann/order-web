<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use App\Support\Phone;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class Customer extends Model implements Authenticatable, TenantScoped
{
    use AuthenticatableTrait, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'session_token',
        'last_login_at',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Todo telefone é gravado no formato canônico ({@see Phone::normalize()}), qualquer
     * que seja o caminho de escrita (checkout, login, admin, perfil, seeder, tinker).
     * É isso que impede a unique parcial `(tenant_id, phone)` de ser contornada por
     * diferença de formato. Vazio vira NULL.
     */
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Phone::normalizeOrNull($value);
    }

    /**
     * Cliente ATIVO (não excluído) do tenant com este telefone, em qualquer grafia.
     */
    public static function findByPhone(string $tenantId, ?string $phone): ?self
    {
        $normalized = Phone::normalizeOrNull($phone);

        if ($normalized === null) {
            return null;
        }

        return static::query()->forTenant($tenantId)->where('phone', $normalized)->first();
    }

    /**
     * Resolve (ou cria) o cliente pelo telefone, de forma segura contra corrida: se outra
     * requisição criar o mesmo número entre o SELECT e o INSERT, a unique do banco recusa
     * o segundo e devolvemos o cliente que venceu, em vez de estourar 500.
     *
     * O INSERT roda num savepoint próprio (DB::transaction aninhada), senão a falha
     * abortaria a transação externa do PostgreSQL (ex.: a do pedido).
     *
     * @param  array<string, mixed>  $attributes  usados só se o cliente for criado
     */
    public static function firstOrCreateByPhone(string $tenantId, string $phone, array $attributes = []): self
    {
        $existing = static::findByPhone($tenantId, $phone);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => static::create(array_merge($attributes, [
                'tenant_id' => $tenantId,
                'phone' => $phone,
            ])));
        } catch (UniqueConstraintViolationException $e) {
            return static::findByPhone($tenantId, $phone) ?? throw $e;
        }
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

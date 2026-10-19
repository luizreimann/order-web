<?php

namespace App\Models;

use App\Enums\GatewayPaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model implements TenantScoped
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'gateway',
        'gateway_payment_id',
        'idempotency_key',
        'amount_cents',
        'status',
        'status_detail',
        'meta',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    public function scopeForGateway(Builder $query, string $gateway): Builder
    {
        return $query->where('gateway', $gateway);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', GatewayPaymentStatus::PENDING->value);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', GatewayPaymentStatus::APPROVED->value);
    }

    /**
     * `status` é o valor BRUTO do gateway (string, conjunto aberto — ver
     * {@see GatewayPaymentStatus}); null quando o gateway mandou um valor desconhecido.
     */
    public function gatewayStatus(): ?GatewayPaymentStatus
    {
        return GatewayPaymentStatus::tryFrom((string) $this->status);
    }

    public function isApproved(): bool
    {
        return $this->gatewayStatus() === GatewayPaymentStatus::APPROVED;
    }

    /** Tentativa que não gerou cobrança aproveitável (E3.4): libera nova tentativa. */
    public function isFailed(): bool
    {
        return in_array($this->status, [
            GatewayPaymentStatus::FAILED->value,
            GatewayPaymentStatus::REJECTED->value,
            GatewayPaymentStatus::CANCELLED->value,
        ], true);
    }

    public function isPending(): bool
    {
        return $this->gatewayStatus()?->isPending() ?? false;
    }
}

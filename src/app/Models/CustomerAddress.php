<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'label',
        'is_default',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\TenantCounter;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'number',
        'status',
        'payment_status',
        'payment_method',
        'customer_name',
        'customer_phone',
        'fulfillment_type',
        'scheduled_for',
        'notes',
        'subtotal_cents',
        'discount_cents',
        'delivery_fee_cents',
        'total_cents',
        'address_line1',
        'address_line2',
        'address_city',
        'address_state',
        'address_postal_code',
        'meta',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'delivery_fee_cents' => 'integer',
        'total_cents' => 'integer',
        'meta' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }


    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->number) return;

            $order->number = DB::transaction(function () use ($order) {
                $counter = TenantCounter::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('key', 'orders')
                    ->lockForUpdate()
                    ->first();

                if (!$counter) {
                    $counter = TenantCounter::create([
                        'tenant_id' => $order->tenant_id,
                        'key' => 'orders',
                        'value' => 0,
                    ]);
                }

                $counter->value = (int) $counter->value + 1;
                $counter->save();

                return $counter->value;
            }, 3);
        });
    }
}
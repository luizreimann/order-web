<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transição de pagamento já aplicada por webhook (E3.3). Ver a migration
 * `create_payment_webhook_events_table` para o contrato de unicidade.
 */
class PaymentWebhookEvent extends Model implements TenantScoped
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'order_id',
        'gateway',
        'gateway_payment_id',
        'status',
        'previous_status',
        'request_id',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}

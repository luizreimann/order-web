<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantCounter extends Model
{
    protected $table = 'tenant_counters';

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];

    public $incrementing = false;

    protected $primaryKey = null; // chave composta
}
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `orders.fulfillment_type` passa a ter CHECK (pickup | delivery), como status e payment_status.
 * Aditiva: não recria nada. Falha se existir linha com outro valor (não existe: o código só grava
 * os dois do enum `FulfillmentType`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_type_valid CHECK (fulfillment_type IN ('pickup', 'delivery'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_fulfillment_type_valid');
    }
};

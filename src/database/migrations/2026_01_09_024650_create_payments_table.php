<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->string('gateway', 50);
            $table->string('gateway_payment_id')->nullable();
            $table->unsignedBigInteger('amount_cents'); // 64 bits (E2.3); >= 0 via CHECK
            $table->string('status', 30)->default('pending');
            $table->string('status_detail')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('order_id');
            $table->index('gateway_payment_id');
            $table->index(['gateway', 'status']);

            // RESTRICT (antes CASCADE): pagamento é registro financeiro; nem o tenant nem
            // o pedido podem ser removidos levando pagamentos junto.
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_cents_nonnegative CHECK (amount_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

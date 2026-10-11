<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transições de pagamento aplicadas por webhook (E3.3 / ARC-005).
     *
     * Uma linha = "o pagamento X do gateway Y passou a ter o status Z". O UNIQUE
     * `(gateway, gateway_payment_id, status)` garante, no banco, que cada transição é
     * aplicada UMA vez, mesmo com reenvios e processos concorrentes; `request_id`
     * (x-request-id do gateway) permite cortar o reenvio de uma mesma notificação sem
     * sequer consultar a API do gateway.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payment_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('gateway', 50);
            $table->string('gateway_payment_id');
            $table->string('status', 30);
            $table->string('previous_status', 30)->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['gateway', 'gateway_payment_id', 'status'], 'payment_webhook_events_transition_unique');
            $table->index(['gateway', 'request_id']);
            $table->index('tenant_id');

            // RESTRICT: o histórico de eventos é registro financeiro (como `payments`).
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('restrict');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

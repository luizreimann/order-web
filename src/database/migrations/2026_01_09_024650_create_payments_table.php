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
            // Nulo enquanto a tentativa foi registrada mas o gateway ainda não respondeu
            // (E3.4: o Payment nasce ANTES da chamada externa).
            $table->string('gateway_payment_id')->nullable();
            // Chave enviada ao gateway (X-Idempotency-Key). Persistida ANTES da chamada,
            // então um reenvio/duplo submit reaproveita a mesma tentativa em vez de cobrar
            // de novo. Formato: `<hash do checkout>` e, nas retentativas após falha,
            // `<hash>:<n>` (o gateway devolveria a falha antiga para a mesma chave).
            $table->string('idempotency_key', 120)->nullable();
            $table->unsignedBigInteger('amount_cents'); // 64 bits (E2.3); >= 0 via CHECK
            // Status BRUTO do gateway (approved, in_process, rejected…), conjunto aberto
            // definido pelo Mercado Pago: sem CHECK de propósito (E2.6). Não confundir com
            // orders.payment_status (unpaid|paid|refunded), que é nosso e tem CHECK.
            // Além dos valores do gateway há dois LOCAIS: `created` (tentativa registrada,
            // gateway ainda não respondeu) e `failed` (falha sem resposta utilizável).
            $table->string('status', 30)->default('pending');
            $table->string('status_detail')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('order_id');
            $table->index(['gateway', 'status']);

            // O par (gateway, gateway_payment_id) é globalmente único: é a chave pela qual
            // o webhook localiza o pagamento (E3.3). NULLs não colidem (tentativas sem id).
            $table->unique(['gateway', 'gateway_payment_id']);
            $table->unique(['gateway', 'idempotency_key']);

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

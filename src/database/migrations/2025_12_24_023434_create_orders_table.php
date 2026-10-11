<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // RESTRICT: pedido é histórico financeiro; apagar um tenant com pedidos
            // tem de ser decisão explícita (exportar/arquivar antes), não efeito colateral.
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->restrictOnDelete();

            // Referência amigável pro admin/cliente (por tenant)
            $table->bigInteger('number')->index(); // geramos sequencial por tenant

            // Status do pedido + pagamento
            $table->string('status')->default('pending'); // pending|confirmed|cancelled|done
            $table->string('payment_status')->default('unpaid'); // unpaid|paid|refunded
            $table->string('payment_method')->nullable(); // pix|cash|card|manual etc.

            // Cliente (MVP)
            $table->string('customer_name');
            $table->string('customer_phone')->nullable(); // whatsapp

            // Entrega/retirada (encomenda)
            $table->string('fulfillment_type')->default('pickup'); // pickup|delivery
            $table->timestampTz('scheduled_for')->nullable(); // data/hora combinada
            $table->text('notes')->nullable();

            // Totais (centavos)
            // 64 bits (E2.3). PG não tem unsigned: >= 0 garantido por CHECK abaixo.
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('delivery_fee_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);

            // Endereço (MVP simples)
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state')->nullable();
            $table->string('address_postal_code')->nullable();

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // NÃO há `softDeletes()` em orders (E2.5): pedido é documento comercial/financeiro
        // e não deve ser "excluído" — o ciclo de vida é o status (`cancelled`).
        // Remoção física também é barrada: payments.order_id é RESTRICT.
        foreach (['subtotal_cents', 'discount_cents', 'delivery_fee_cents', 'total_cents'] as $column) {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_{$column}_nonnegative CHECK ({$column} >= 0)");
        }

        // E2.6: valores iguais aos de App\Enums\OrderStatus / PaymentStatus (um teste
        // compara o CHECK com os cases dos enums). Literais de propósito: a migration
        // descreve o schema NESTE momento e não deve mudar se o enum evoluir — nesse
        // caso, uma migration nova altera o CHECK.
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('pending', 'confirmed', 'done', 'cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_valid CHECK (payment_status IN ('unpaid', 'paid', 'refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

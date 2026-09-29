<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

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
            $table->integer('subtotal_cents')->default(0);
            $table->integer('discount_cents')->default(0);
            $table->integer('delivery_fee_cents')->default(0);
            $table->integer('total_cents')->default(0);

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
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

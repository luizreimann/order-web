<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();
            $table->uuid('order_id')->index();
            $table->uuid('product_id')->nullable()->index(); // pode ser null se produto foi removido depois

            // Snapshot do produto no momento do pedido
            $table->string('name');
            $table->string('sku')->nullable();

            $table->integer('quantity')->default(1);

            // Preço unitário no momento do pedido
            $table->integer('unit_price_cents')->default(0);
            $table->integer('total_cents')->default(0);

            // Customizações do item (tamanho, sabor, observações etc.)
            $table->text('notes')->nullable();
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // RESTRICT no tenant: itens são histórico financeiro (como orders).
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->restrictOnDelete();
            // CASCADE no pedido: o item é parte do agregado Order (hoje pedido nem é apagável).
            $table->foreignUuid('order_id')->index()->constrained('orders')->cascadeOnDelete();
            // SET NULL: o item guarda snapshot (name/sku/preço); remover o produto
            // fisicamente não pode apagar nem bloquear o histórico.
            $table->foreignUuid('product_id')->nullable()->index()->constrained('products')->nullOnDelete();

            // Snapshot do produto no momento do pedido
            $table->string('name');
            $table->string('sku')->nullable();

            $table->integer('quantity')->default(1);

            // Preço unitário no momento do pedido
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);

            // Customizações do item (tamanho, sabor, observações etc.)
            $table->text('notes')->nullable();
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        foreach (['unit_price_cents', 'total_cents'] as $column) {
            DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_{$column}_nonnegative CHECK ({$column} >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

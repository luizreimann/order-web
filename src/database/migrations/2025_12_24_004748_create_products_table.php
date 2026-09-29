<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

            // Identidade / catálogo
            $table->string('name');
            $table->string('slug')->nullable();      // opcional por enquanto
            $table->string('sku')->nullable();       // opcional
            $table->text('description')->nullable();

            // Preço (robusto)
            $table->unsignedBigInteger('price_cents');        // ex: 1990 = R$ 19,90
            $table->unsignedBigInteger('compare_at_cents')->nullable(); // preço riscado

            // Flags
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);  // destaque
            $table->integer('sort_order')->default(0);       // ordenação manual

            // Campos úteis para encomenda (simples)
            $table->integer('min_days_notice')->nullable(); // ex.: 2 = precisa 2 dias
            $table->boolean('requires_scheduling')->default(false); // pede data/hora no checkout

            // Extensível (Postgres)
            $table->jsonb('meta')->nullable();

            $table->timestamps();

            // Índices úteis por tenant
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'is_featured']);
            $table->index(['tenant_id', 'sort_order']);
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

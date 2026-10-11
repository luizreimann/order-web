<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // onDelete CASCADE: catálogo é recriável e não tem valor sem o tenant.
            // (Apagar um tenant com pedidos é bloqueado por orders.tenant_id RESTRICT.)
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            // Identidade / catálogo
            $table->string('name');
            $table->string('slug');                  // NOT NULL (E2.4); gerado pelo model (GeneratesUniqueSlug)
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
            $table->softDeletes(); // E2.5

            // Índices úteis por tenant
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'is_featured']);
            $table->index(['tenant_id', 'sort_order']);
            // Alvo das FKs compostas dos pivôs (E2.2): só uma linha com este par
            // (tenant_id, id) pode ser referenciada por category_product/showcase_product.
            $table->unique(['tenant_id', 'id']);
        });

        // E2.5: unique PARCIAL — slug de produto excluído (soft delete) pode ser reutilizado.
        // O índice cobre só linhas ativas; é o que torna "recriar o mesmo slug" possível
        // sem hard delete. Restaurar um registro colidindo com um ativo falha (23505).
        DB::statement('CREATE UNIQUE INDEX products_tenant_id_slug_unique ON products (tenant_id, slug) WHERE deleted_at IS NULL');

        // PG não tem `unsigned`: não-negatividade via CHECK.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_cents_nonnegative CHECK (price_cents >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_compare_at_cents_nonnegative CHECK (compare_at_cents IS NULL OR compare_at_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

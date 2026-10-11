<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            // E2.2 — isolamento cross-tenant NO BANCO:
            // as FKs são COMPOSTAS (tenant_id, X_id) -> X (tenant_id, id) e apontam
            // para as `unique (tenant_id, id)` de products/categories. Logo a linha
            // só existe se produto E categoria pertencem ao tenant_id gravada aqui.
            // CASCADE: o vínculo some junto com qualquer das pontas (e com o tenant).
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('category_id');
            $table->uuid('product_id');

            // PK composta com tenant_id = a "unique composta" do pivô.
            $table->primary(['tenant_id', 'category_id', 'product_id']);

            $table->index('category_id');
            $table->index('product_id');

            $table->foreign(['tenant_id', 'category_id'])
                ->references(['tenant_id', 'id'])->on('categories')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};

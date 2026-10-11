<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcase_product', function (Blueprint $table) {
            // E2.2 — igual a category_product: FKs COMPOSTAS (tenant_id, X_id) -> X (tenant_id, id).
            // A linha só existe se vitrine E produto são do tenant_id gravado aqui; CASCADE
            // com qualquer das pontas (e com o tenant).
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('showcase_id');
            $table->uuid('product_id');
            $table->integer('sort_order')->default(0);

            $table->primary(['tenant_id', 'showcase_id', 'product_id']);

            $table->index('showcase_id');
            $table->index('product_id');

            $table->foreign(['tenant_id', 'showcase_id'])
                ->references(['tenant_id', 'id'])->on('showcases')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcase_product');
    }
};

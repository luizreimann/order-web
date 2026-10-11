<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete(); // catálogo: cascade

            $table->string('name');
            $table->string('slug'); // NOT NULL (E2.4); gerado pelo model (GeneratesUniqueSlug)
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->jsonb('meta')->nullable();

            $table->timestamps();
            $table->softDeletes(); // E2.5

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'sort_order']);
            // Alvo das FKs compostas dos pivôs (E2.2).
            $table->unique(['tenant_id', 'id']);
        });

        // E2.5: unique parcial — ver comentário em create_products_table.
        DB::statement('CREATE UNIQUE INDEX categories_tenant_id_slug_unique ON categories (tenant_id, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

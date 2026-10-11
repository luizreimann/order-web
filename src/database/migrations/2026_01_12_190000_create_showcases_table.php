<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete(); // config do tenant: cascade

            $table->string('name');
            $table->string('slug'); // NOT NULL (E2.4); gerado pelo model (GeneratesUniqueSlug)
            $table->string('type');
            $table->string('display_mode', 20)->default('list'); // fundido de add_display_mode_to_showcases_table

            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('max_items')->default(8);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->jsonb('meta')->nullable(); // E2.4: json -> jsonb (DB-007)
            $table->timestamps();
            $table->softDeletes(); // E2.5

            // Alvo da FK composta de showcase_product (E2.2).
            $table->unique(['tenant_id', 'id']);
        });

        // E2.5: unique parcial — ver comentário em create_products_table.
        DB::statement('CREATE UNIQUE INDEX showcases_tenant_id_slug_unique ON showcases (tenant_id, slug) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('showcases');
    }
};

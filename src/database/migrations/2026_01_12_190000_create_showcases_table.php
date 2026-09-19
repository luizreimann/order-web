<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('type');

            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('max_items')->default(8);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcases');
    }
};

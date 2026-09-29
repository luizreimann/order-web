<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

            $table->string('name');
            $table->string('slug')->nullable(); // opcional no MVP; geramos automaticamente
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'sort_order']);
            $table->unique(['tenant_id', 'slug']); // permite múltiplos NULL no PG
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

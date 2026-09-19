<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable(); // whatsapp

            $table->boolean('is_active')->default(true);

            $table->jsonb('meta')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);

            // Deduplicação opcional (recomendado no MVP):
            // Se você não quiser travar, remova.
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
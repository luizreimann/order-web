<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de login por WhatsApp. O código em claro não entra: `code_hash` é
 * HMAC-SHA256 com a APP_KEY. O índice cobre a busca por loja e telefone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // RESTRICT: a linha carrega telefone; não some com o tenant por acidente.
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->string('phone');
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('idempotency_key', 64);

            $table->timestamps();

            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_codes');
    }
};

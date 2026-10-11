<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // RESTRICT: cliente é PII + origem do histórico de pedidos; não some com o
            // tenant por acidente.
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->restrictOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable(); // whatsapp

            // (fundido de add_session_fields_to_customers_table)
            $table->string('session_token', 255)->nullable()->index();
            $table->timestamp('last_login_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->jsonb('meta')->nullable();

            $table->timestamps();
            $table->softDeletes(); // E2.5

            $table->index(['tenant_id', 'is_active']);

        });

        // Deduplicação por telefone — só entre clientes ATIVOS (E2.5): com soft delete o
        // mesmo telefone pode ser cadastrado de novo após a exclusão. Telefone NULL
        // continua não colidindo (semântica do PG).
        DB::statement('CREATE UNIQUE INDEX customers_tenant_id_phone_unique ON customers (tenant_id, phone) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

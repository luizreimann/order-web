<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // D2 (04-decisions.md): `tenant_id` PERMANECE NULLABLE. Um `platform_admin`
            // não pertence a nenhum tenant. A FK entra; a nulidade não pode regredir.
            // (NULL não é checado pela FK, então platform_admin persiste normalmente.)
            //
            // onDelete CASCADE: credenciais de admin de um tenant não sobrevivem a ele.
            //
            // Esta migration NÃO foi fundida em `create_users_table`: `users` (0001_01_01_...)
            // é criada ANTES de `tenants` (2025_12_24_...), então a FK não pode existir lá,
            // e os timestamps das migrations não devem mudar.
            $table->foreignUuid('tenant_id')->nullable()->after('id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('role')->default('tenant_admin')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'role']);
        });
    }
};

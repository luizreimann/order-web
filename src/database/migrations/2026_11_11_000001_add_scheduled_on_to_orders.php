<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dia de calendário da loja. `date`, não `timestampTz`: meia-noite em UTC
 * viraria o dia anterior em America/Sao_Paulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('scheduled_on')->nullable();
            $table->index(['tenant_id', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'scheduled_on']);
            $table->dropColumn('scheduled_on');
        });
    }
};

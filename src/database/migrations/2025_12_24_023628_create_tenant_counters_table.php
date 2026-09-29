<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_counters', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('key', 50);
            $table->bigInteger('value')->default(0);
            $table->timestamps();

            $table->primary(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_counters');
    }
};

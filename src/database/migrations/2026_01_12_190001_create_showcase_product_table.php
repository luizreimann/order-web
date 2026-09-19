<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcase_product', function (Blueprint $table) {
            $table->foreignUuid('showcase_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->primary(['showcase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcase_product');
    }
};

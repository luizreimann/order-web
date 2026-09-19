<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->uuid('category_id');
            $table->uuid('product_id');

            $table->primary(['category_id', 'product_id']);

            $table->index('category_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
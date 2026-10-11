<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // SET NULL: pedido NÃO é apagado em cascata junto com o cliente; guarda snapshot
            // (customer_name/customer_phone). Nullable também por causa do checkout de visitante.
            //
            // Não fundida em create_orders_table: `customers` é criada DEPOIS de `orders`
            // (a FK precisa da tabela referenciada) e os timestamps não mudam.
            $table->foreignUuid('customer_id')->nullable()->after('tenant_id')->index()
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};

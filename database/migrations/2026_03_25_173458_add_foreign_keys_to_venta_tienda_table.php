<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('venta_tienda', function (Blueprint $table) {
            $table->foreign(['id_cliente'], 'venta_tienda_ibfk_1')->references(['id_cliente'])->on('clientes')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venta_tienda', function (Blueprint $table) {
            $table->dropForeign('venta_tienda_ibfk_1');
        });
    }
};

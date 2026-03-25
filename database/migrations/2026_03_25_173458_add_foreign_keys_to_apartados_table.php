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
        Schema::table('apartados', function (Blueprint $table) {
            $table->foreign(['id_cliente'], 'apartados_ibfk_1')->references(['id_cliente'])->on('clientes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id_producto'], 'apartados_ibfk_2')->references(['id_producto'])->on('producto_tienda')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apartados', function (Blueprint $table) {
            $table->dropForeign('apartados_ibfk_1');
            $table->dropForeign('apartados_ibfk_2');
        });
    }
};

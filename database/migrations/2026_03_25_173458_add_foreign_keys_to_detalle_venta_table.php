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
        Schema::table('detalle_venta', function (Blueprint $table) {
            $table->foreign(['id_venta'], 'detalle_venta_ibfk_1')->references(['id_venta'])->on('venta_tienda')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id_producto'], 'detalle_venta_ibfk_2')->references(['id_producto'])->on('producto_tienda')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detalle_venta', function (Blueprint $table) {
            $table->dropForeign('detalle_venta_ibfk_1');
            $table->dropForeign('detalle_venta_ibfk_2');
        });
    }
};

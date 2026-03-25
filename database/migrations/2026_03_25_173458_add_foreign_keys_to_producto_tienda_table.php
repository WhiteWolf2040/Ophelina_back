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
        Schema::table('producto_tienda', function (Blueprint $table) {
            $table->foreign(['id_prenda'], 'producto_tienda_ibfk_1')->references(['id_prenda'])->on('prendas')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id_empresa'], 'producto_tienda_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('producto_tienda', function (Blueprint $table) {
            $table->dropForeign('producto_tienda_ibfk_1');
            $table->dropForeign('producto_tienda_ibfk_empresa');
        });
    }
};

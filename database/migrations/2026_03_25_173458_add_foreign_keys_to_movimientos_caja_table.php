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
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->foreign(['id_usuario'], 'movimientos_caja_ibfk_1')->references(['id_usuario'])->on('usuario')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_pago'], 'movimientos_caja_ibfk_2')->references(['id_pago'])->on('pagos')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropForeign('movimientos_caja_ibfk_1');
            $table->dropForeign('movimientos_caja_ibfk_2');
        });
    }
};

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
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->integer('id_movimiento', true);
            $table->enum('tipo', ['prestamo', 'pago', 'venta', 'gasto'])->nullable();
            $table->decimal('monto', 10)->nullable();
            $table->text('descripcion')->nullable();
            $table->dateTime('fecha')->nullable()->useCurrent();
            $table->integer('id_usuario')->nullable()->index('movimientos_caja_id_usuario_idx');
            $table->integer('id_pago')->nullable()->index('movimientos_caja_id_pago_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};

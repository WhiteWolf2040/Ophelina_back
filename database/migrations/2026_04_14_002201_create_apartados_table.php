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
        Schema::create('apartados', function (Blueprint $table) {
            $table->integer('id_apartado', true);
            $table->integer('id_cliente')->nullable()->index('apartados_id_cliente_idx');
            $table->integer('id_producto')->nullable()->index('apartados_id_producto_idx');
            $table->dateTime('fecha_apartado')->nullable()->useCurrent();
            $table->date('fecha_expiracion')->nullable();
            $table->enum('estado', ['activo', 'completado', 'expirado', 'cancelado'])->nullable()->default('activo');
            $table->text('notas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apartados');
    }
};

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
        Schema::create('venta_tienda', function (Blueprint $table) {
            $table->integer('id_venta', true);
            $table->integer('id_cliente')->nullable()->index('id_cliente');
            $table->dateTime('fecha_venta')->nullable()->useCurrent();
            $table->decimal('total', 10)->nullable();
            $table->enum('metodo_pago', ['efectivo', 'tarjeta', 'transferencia', 'mixto'])->nullable();
            $table->enum('estado', ['completada', 'cancelada', 'pendiente'])->nullable()->default('completada');
            $table->string('folio', 20)->nullable()->unique('folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venta_tienda');
    }
};

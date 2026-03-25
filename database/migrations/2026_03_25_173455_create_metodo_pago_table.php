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
        Schema::create('metodo_pago', function (Blueprint $table) {
            $table->integer('id_metodo', true);
            $table->integer('id_cliente')->nullable()->index('id_cliente');
            $table->string('proveedor', 50)->nullable();
            $table->string('token')->nullable();
            $table->string('ultimos_4_digitos', 4)->nullable();
            $table->enum('tipo', ['tarjeta', 'transferencia', 'paypal'])->nullable();
            $table->string('marca', 50)->nullable();
            $table->boolean('activo')->nullable()->default(true);
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metodo_pago');
    }
};

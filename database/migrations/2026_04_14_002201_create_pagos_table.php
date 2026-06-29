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
        Schema::create('pagos', function (Blueprint $table) {
            $table->integer('id_pago', true);
            $table->integer('id_empeno')->nullable()->index('pagos_id_empeno_idx');
            $table->integer('id_amortizacion')->nullable()->index('pagos_id_amortizacion_idx');
            $table->date('fecha_pago')->nullable();
            $table->decimal('capital_pagado', 10)->nullable();
            $table->decimal('interes_pagado', 10)->nullable();
            $table->decimal('iva_pagado', 10)->nullable();
            $table->decimal('monto_total', 10)->nullable();
            $table->enum('tipo_pago', ['interes', 'abono', 'liquidacion', 'prorroga'])->nullable();
            $table->enum('metodo_pago', ['efectivo', 'transferencia', 'tarjeta', 'deposito'])->nullable();
            $table->string('referencia', 100)->nullable();
            $table->string('comprobante')->nullable();
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};

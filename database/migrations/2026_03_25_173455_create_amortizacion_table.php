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
        Schema::create('amortizacion', function (Blueprint $table) {
            $table->integer('id_amortizacion', true);
            $table->integer('id_empeno')->nullable()->index('id_empeno');
            $table->decimal('saldo_inicial', 10)->nullable();
            $table->decimal('saldo_final', 10)->nullable();
            $table->integer('numero_pago')->nullable();
            $table->date('fecha_pago_programado')->nullable();
            $table->date('fecha_pago_real')->nullable();
            $table->decimal('capital', 10)->nullable();
            $table->decimal('interes', 10)->nullable();
            $table->decimal('iva_interes', 10)->nullable();
            $table->decimal('monto_total', 10)->nullable();
            $table->decimal('monto_pagado', 10)->nullable();
            $table->enum('tipo_pago', ['efectivo', 'transferencia', 'tarjeta'])->nullable();
            $table->enum('estado', ['pendiente', 'pagado', 'vencido'])->nullable()->default('pendiente');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amortizacion');
    }
};

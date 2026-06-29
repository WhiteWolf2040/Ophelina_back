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
        Schema::create('empeno', function (Blueprint $table) {
            $table->integer('id_empeno', true);
            $table->integer('id_empresa')->index('id_empresa');
            $table->integer('id_cliente')->index('id_cliente');
            $table->integer('id_prenda')->index('id_prenda');
            $table->integer('id_aval')->nullable()->index('id_aval');
            $table->integer('id_tasa')->nullable()->index('id_tasa');
            $table->date('fecha_empeno')->nullable();
            $table->decimal('monto_prestado', 10)->nullable();
            $table->decimal('intereses', 5)->nullable();
            $table->decimal('iva_porcentaje', 5)->nullable()->default(16);
            $table->date('fecha_vencimiento')->nullable();
            $table->enum('estado', ['activo', 'pagado', 'vencido', 'prorrogado', 'cancelado'])->nullable()->default('activo');
            $table->string('folio', 20)->nullable()->unique('folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empeno');
    }
};

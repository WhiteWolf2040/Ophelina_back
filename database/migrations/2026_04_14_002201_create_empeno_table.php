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
            $table->integer('id_empresa')->index('empeno_id_empresa_idx');
            $table->integer('id_cliente')->index('empeno_id_cliente_idx');
            $table->integer('id_prenda')->index('empeno_id_prenda_idx');
            $table->integer('id_aval')->nullable()->index('empeno_id_aval_idx');
            $table->integer('id_tasa')->nullable()->index('empeno_id_tasa_idx');
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

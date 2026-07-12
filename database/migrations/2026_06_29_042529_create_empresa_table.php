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
        Schema::create('empresa', function (Blueprint $table) {
            $table->integer('id_empresa', true);
            $table->string('nombre', 100);
            $table->string('nombre_comercial', 100)->nullable();
            $table->string('rfc', 13)->unique('rfc');
            $table->string('telefono', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('direccion')->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('estado', 100)->nullable();
            $table->string('codigo_postal', 10)->nullable();
            $table->string('logo_url')->nullable();
            $table->decimal('precio_oro_gramo', 10)->nullable()->default(850);
            $table->decimal('precio_oro_onza', 10)->nullable();
            $table->dateTime('ultima_actualizacion_oro')->nullable();
            $table->boolean('activo')->nullable()->default(true);
            $table->integer('id_plan')->nullable()->index('idx_empresa_plan');
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
            $table->date('fecha_inicio_plan')->nullable();
            $table->date('fecha_fin_plan')->nullable();
            $table->boolean('plan_activo')->nullable()->default(true)->comment('1=activo, 0=inactivo/vencido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresa');
    }
};

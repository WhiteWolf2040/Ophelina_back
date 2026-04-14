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
        Schema::create('permisos', function (Blueprint $table) {
            $table->integer('id_permiso', true);
            $table->integer('id_empresa')->index('id_empresa');
            $table->string('nombre', 50)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('modulo', 50)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->nullable()->default('activo');

            $table->unique(['nombre', 'id_empresa'], 'nombre_empresa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permisos');
    }
};

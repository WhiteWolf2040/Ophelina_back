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
            $table->boolean('activo')->nullable()->default(true);
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
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

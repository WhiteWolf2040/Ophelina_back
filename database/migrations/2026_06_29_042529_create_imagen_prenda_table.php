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
        Schema::create('imagen_prenda', function (Blueprint $table) {
            $table->integer('id_imagen', true);
            $table->integer('id_prenda')->nullable()->index('id_prenda');
            $table->string('ruta_archivo')->nullable();
            $table->boolean('es_principal')->nullable()->default(false);
            $table->integer('orden')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imagen_prenda');
    }
};

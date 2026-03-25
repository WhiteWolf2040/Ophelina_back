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
        Schema::create('producto_tienda', function (Blueprint $table) {
            $table->integer('id_producto', true);
            $table->integer('id_empresa')->index('id_empresa');
            $table->integer('id_prenda')->nullable()->index('id_prenda');
            $table->string('nombre', 100)->nullable();
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 10)->nullable();
            $table->integer('descuento')->nullable()->default(0);
            $table->integer('stock')->nullable()->default(1);
            $table->enum('estado_producto', ['Nuevo', 'Como nuevo', 'Buen estado', 'Aceptable'])->nullable();
            $table->boolean('visible')->nullable()->default(true);
            $table->boolean('destacado')->nullable()->default(false);
            $table->string('imagen_url')->nullable();
            $table->date('fecha_publicacion')->nullable()->default('curdate()');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_tienda');
    }
};

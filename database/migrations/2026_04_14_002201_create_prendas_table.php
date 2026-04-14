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
        Schema::create('prendas', function (Blueprint $table) {
            $table->integer('id_prenda', true);
            $table->integer('id_empresa')->index('id_empresa');
            $table->string('descripcion')->nullable();
            $table->enum('tipo', ['Joyería', 'Electrónica', 'Relojes', 'Herramientas', 'Instrumentos', 'Otros'])->nullable();
            $table->string('material', 100)->nullable();
            $table->decimal('peso_gramos', 10)->nullable();
            $table->decimal('valor_estimado', 10)->nullable();
            $table->enum('estado', ['Disponible', 'En Empeño', 'Vendido', 'Vencido', 'Apartado'])->nullable()->default('Disponible');
            $table->text('quitas')->nullable();
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
            $table->string('codigo_barras', 50)->nullable()->unique('codigo_barras');
            $table->string('imagen_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prendas');
    }
};

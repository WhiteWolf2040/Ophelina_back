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
        Schema::create('planes_saas', function (Blueprint $table) {
            $table->integer('id_plan', true);
            $table->string('nombre', 50);
            $table->string('clave', 20)->unique('clave')->comment('free, basico, profesional, empresarial');
            $table->decimal('precio_mensual', 10)->nullable()->default(0);
            $table->integer('max_empleados')->nullable()->default(1);
            $table->integer('max_clientes')->nullable()->default(100);
            $table->integer('max_prendas')->nullable()->default(500);
            $table->integer('max_empenos_activos')->nullable()->default(50);
            $table->integer('dias_prueba')->nullable()->default(0);
            $table->boolean('activo')->nullable()->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes_saas');
    }
};

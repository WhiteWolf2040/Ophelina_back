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
        Schema::create('tasas_interes', function (Blueprint $table) {
            $table->integer('id_tasa', true);
            $table->string('nombre', 50)->nullable();
            $table->decimal('porcentaje', 5)->nullable();
            $table->integer('plazo_dias')->nullable();
            $table->boolean('activo')->nullable()->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasas_interes');
    }
};

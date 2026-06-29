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
        Schema::create('precio_oro', function (Blueprint $table) {
            $table->integer('id_precio', true);
            $table->decimal('precio_gramo_24k', 10);
            $table->decimal('precio_gramo_22k', 10)->nullable();
            $table->decimal('precio_gramo_21k', 10)->nullable();
            $table->decimal('precio_gramo_18k', 10)->nullable();
            $table->decimal('precio_gramo_14k', 10)->nullable();
            $table->decimal('precio_gramo_10k', 10)->nullable();
            $table->decimal('precio_onza', 10)->nullable();
            $table->string('moneda', 3)->nullable()->default('MXN');
            $table->string('fuente', 100)->nullable();
            $table->dateTime('fecha_actualizacion')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('precio_oro');
    }
};

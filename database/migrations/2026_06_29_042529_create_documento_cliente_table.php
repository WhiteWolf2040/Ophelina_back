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
        Schema::create('documento_cliente', function (Blueprint $table) {
            $table->integer('id_documento', true);
            $table->integer('id_cliente')->index('id_cliente');
            $table->string('tipo_documento', 50)->nullable();
            $table->string('titular', 100)->nullable();
            $table->string('archivo')->nullable();
            $table->dateTime('fecha_subida')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documento_cliente');
    }
};

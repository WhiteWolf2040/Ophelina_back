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
        Schema::create('rol', function (Blueprint $table) {
            $table->integer('id_rol', true);
            $table->integer('id_empresa')->index('rol_id_empresa_idx');
            $table->string('nombre', 50);
            $table->text('descripcion')->nullable();
            $table->integer('nivel')->nullable()->default(1);

            $table->unique(['nombre', 'id_empresa'], 'rol_nombre_empresa_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rol');
    }
};

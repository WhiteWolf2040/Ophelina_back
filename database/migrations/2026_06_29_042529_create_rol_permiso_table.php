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
        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->integer('id_rol_permiso', true);
            $table->integer('id_empresa')->index('id_empresa');
            $table->integer('id_rol')->nullable()->index('id_rol');
            $table->integer('id_permiso')->nullable()->index('id_permiso');
            $table->boolean('permitido')->nullable()->default(true);

            $table->unique(['id_rol', 'id_permiso', 'id_empresa'], 'rol_permiso_empresa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rol_permiso');
    }
};

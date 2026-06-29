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
        Schema::create('usuario', function (Blueprint $table) {
            $table->integer('id_usuario', true);
            $table->integer('id_rol')->index('usuario_id_rol_idx');
            $table->integer('id_empresa')->index('usuario_id_empresa_idx');
            $table->string('nombre', 100);
            $table->string('correo', 100)->unique('correo');
            $table->string('contrasena');
            $table->string('telefono', 20)->nullable();
            $table->string('foto_perfil')->nullable();
            $table->boolean('activo')->nullable()->default(true);
            $table->dateTime('fecha_registro')->nullable()->useCurrent();
            $table->dateTime('ultimo_acceso')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuario');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. PRIMERO: Eliminar el índice si existe
        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasIndex('clientes', 'id_empresa')) {
                $table->dropIndex('id_empresa');
            }
        });

        // 2. Crear la tabla si no existe
        if (!Schema::hasTable('clientes')) {
            Schema::create('clientes', function (Blueprint $table) {
                $table->integer('id_cliente', true);
                $table->integer('id_usuario')->nullable()->index('id_usuario');
                $table->integer('id_empresa')->index('id_empresa');
                $table->string('nombre', 100);
                $table->string('apellido', 100)->nullable();
                $table->string('telefono', 20);
                $table->string('correo', 100);
                $table->text('direccion');
                $table->string('codigo_postal', 10)->nullable();
                $table->string('ciudad', 100)->nullable();
                $table->string('estado', 100)->nullable();
                $table->dateTime('fecha_registro')->useCurrentOnUpdate()->nullable();
                $table->boolean('activo')->nullable()->default(true);
                $table->string('tipo_identificacion', 50)->nullable();
                $table->string('numero_identificacion', 50)->nullable();
                $table->string('foto_perfil')->nullable();
                $table->string('foto_ine')->nullable();
            });
        }

        // 3. Crear índices solo si no existen
        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasIndex('clientes', 'id_usuario')) {
                $table->index('id_usuario', 'id_usuario');
            }
            if (!Schema::hasIndex('clientes', 'id_empresa')) {
                $table->index('id_empresa', 'id_empresa');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
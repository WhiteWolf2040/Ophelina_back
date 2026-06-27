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
        // Verificar si la tabla ya existe antes de crearla
        if (!Schema::hasTable('clientes')) {
            Schema::create('clientes', function (Blueprint $table) {
                $table->integer('id_cliente', true);
                $table->integer('id_usuario')->nullable();
                $table->integer('id_empresa');
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

        // Crear índices SOLO si no existen
        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasIndex('clientes', 'id_usuario')) {
                $table->index('id_usuario', 'id_usuario');
            }
            if (!Schema::hasIndex('clientes', 'id_empresa')) {
                $table->index('id_empresa', 'id_empresa');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
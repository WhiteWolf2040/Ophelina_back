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
        Schema::table('clientes', function (Blueprint $table) {
            $table->foreign(['id_usuario'], 'clientes_ibfk_1')->references(['id_usuario'])->on('usuario')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['id_empresa'], 'clientes_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropForeign('clientes_ibfk_1');
            $table->dropForeign('clientes_ibfk_empresa');
        });
    }
};

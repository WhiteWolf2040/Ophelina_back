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
        Schema::table('usuario', function (Blueprint $table) {
            $table->foreign(['id_rol'], 'usuario_ibfk_1')->references(['id_rol'])->on('rol')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_empresa'], 'usuario_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuario', function (Blueprint $table) {
            $table->dropForeign('usuario_ibfk_1');
            $table->dropForeign('usuario_ibfk_empresa');
        });
    }
};

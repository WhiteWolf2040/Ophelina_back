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
        Schema::table('rol_permiso', function (Blueprint $table) {
            $table->foreign(['id_rol'], 'rol_permiso_ibfk_1')->references(['id_rol'])->on('rol')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id_permiso'], 'rol_permiso_ibfk_2')->references(['id_permiso'])->on('permisos')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rol_permiso', function (Blueprint $table) {
            $table->dropForeign('rol_permiso_ibfk_1');
            $table->dropForeign('rol_permiso_ibfk_2');
        });
    }
};

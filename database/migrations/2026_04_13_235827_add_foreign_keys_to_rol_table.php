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
        Schema::table('rol', function (Blueprint $table) {
            $table->foreign(['id_empresa'], 'rol_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rol', function (Blueprint $table) {
            $table->dropForeign('rol_ibfk_empresa');
        });
    }
};

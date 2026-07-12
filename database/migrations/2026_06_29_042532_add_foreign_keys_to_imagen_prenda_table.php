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
        Schema::table('imagen_prenda', function (Blueprint $table) {
            $table->foreign(['id_prenda'], 'imagen_prenda_ibfk_1')->references(['id_prenda'])->on('prendas')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imagen_prenda', function (Blueprint $table) {
            $table->dropForeign('imagen_prenda_ibfk_1');
        });
    }
};

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
        Schema::table('aval', function (Blueprint $table) {
            $table->foreign(['id_empresa'], 'aval_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aval', function (Blueprint $table) {
            $table->dropForeign('aval_ibfk_empresa');
        });
    }
};

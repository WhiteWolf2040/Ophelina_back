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
        Schema::table('documento_aval', function (Blueprint $table) {
            $table->foreign(['id_aval'], 'documento_aval_ibfk_1')->references(['id_aval'])->on('aval')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documento_aval', function (Blueprint $table) {
            $table->dropForeign('documento_aval_ibfk_1');
        });
    }
};

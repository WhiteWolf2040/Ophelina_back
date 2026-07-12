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
        Schema::table('amortizacion', function (Blueprint $table) {
            $table->foreign(['id_empeno'], 'amortizacion_ibfk_1')->references(['id_empeno'])->on('empeno')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortizacion', function (Blueprint $table) {
            $table->dropForeign('amortizacion_ibfk_1');
        });
    }
};

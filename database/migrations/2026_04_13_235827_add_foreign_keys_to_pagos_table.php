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
        Schema::table('pagos', function (Blueprint $table) {
            $table->foreign(['id_empeno'], 'pagos_ibfk_1')->references(['id_empeno'])->on('empeno')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id_amortizacion'], 'pagos_ibfk_2')->references(['id_amortizacion'])->on('amortizacion')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropForeign('pagos_ibfk_1');
            $table->dropForeign('pagos_ibfk_2');
        });
    }
};

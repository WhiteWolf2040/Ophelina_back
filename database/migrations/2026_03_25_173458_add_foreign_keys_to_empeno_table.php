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
        Schema::table('empeno', function (Blueprint $table) {
            $table->foreign(['id_cliente'], 'empeno_ibfk_1')->references(['id_cliente'])->on('clientes')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_prenda'], 'empeno_ibfk_2')->references(['id_prenda'])->on('prendas')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_aval'], 'empeno_ibfk_3')->references(['id_aval'])->on('aval')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_tasa'], 'empeno_ibfk_4')->references(['id_tasa'])->on('tasas_interes')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['id_empresa'], 'empeno_ibfk_empresa')->references(['id_empresa'])->on('empresa')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empeno', function (Blueprint $table) {
            $table->dropForeign('empeno_ibfk_1');
            $table->dropForeign('empeno_ibfk_2');
            $table->dropForeign('empeno_ibfk_3');
            $table->dropForeign('empeno_ibfk_4');
            $table->dropForeign('empeno_ibfk_empresa');
        });
    }
};

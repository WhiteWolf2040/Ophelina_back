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
        Schema::table('documento_cliente', function (Blueprint $table) {
            $table->foreign(['id_cliente'], 'documento_cliente_ibfk_1')->references(['id_cliente'])->on('clientes')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documento_cliente', function (Blueprint $table) {
            $table->dropForeign('documento_cliente_ibfk_1');
        });
    }
};

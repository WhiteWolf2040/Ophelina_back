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
        Schema::create('detalle_venta', function (Blueprint $table) {
            $table->integer('id_detalle', true);
            $table->integer('id_venta')->nullable()->index('id_venta');
            $table->integer('id_producto')->nullable()->index('id_producto');
            $table->integer('cantidad')->nullable()->default(1);
            $table->decimal('precio_unitario', 10)->nullable();
            $table->decimal('subtotal', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_venta');
    }
};

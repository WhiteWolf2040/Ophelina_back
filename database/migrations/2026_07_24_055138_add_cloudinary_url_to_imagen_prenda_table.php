<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imagen_prenda', function (Blueprint $table) {
            $table->string('cloudinary_url')->nullable()->after('imagen_mime');
        });
    }

    public function down(): void
    {
        Schema::table('imagen_prenda', function (Blueprint $table) {
            $table->dropColumn('cloudinary_url');
        });
    }
};
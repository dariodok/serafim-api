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
        Schema::create('imagenes_productos_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_base_id')->constrained('productos_base');
            $table->string('disco')->default('public');
            $table->string('ruta');
            $table->string('texto_alternativo')->nullable();
            $table->unsignedInteger('orden')->default(1);
            $table->boolean('principal')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imagenes_productos_base');
    }
};

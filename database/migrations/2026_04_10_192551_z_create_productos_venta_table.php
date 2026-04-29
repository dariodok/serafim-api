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
        Schema::create('productos_venta', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 12, 2);
            $table->unsignedInteger('peso_gramos')->nullable();
            $table->decimal('alto_cm', 8, 2)->nullable();
            $table->decimal('ancho_cm', 8, 2)->nullable();
            $table->decimal('largo_cm', 8, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos_venta');
    }
};

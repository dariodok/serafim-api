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
        Schema::create('productos_venta_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_venta_id')->constrained('productos_venta');
            $table->foreignId('producto_base_id')->constrained('productos_base');
            $table->unsignedInteger('cantidad_requerida');
            $table->timestamps();
            
            $table->unique(['producto_venta_id', 'producto_base_id'], 'prod_venta_comp_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos_venta_componentes');
    }
};

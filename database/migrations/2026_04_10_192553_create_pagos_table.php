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
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->string('medio_pago');
            $table->string('estado');
            $table->decimal('monto', 12, 2);
            $table->string('moneda')->default('ARS');
            $table->boolean('es_manual')->default(false);
            $table->timestamp('fecha_pago')->nullable();
            $table->string('referencia_externa')->nullable();
            $table->string('referencia_secundaria')->nullable();
            $table->string('comprobante_manual')->nullable();
            $table->json('datos_externos')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};

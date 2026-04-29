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
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('numero_venta')->unique();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('datos_facturacion_id')->nullable()->constrained('datos_facturacion');
            $table->string('tipo_entrega');
            $table->string('estado_venta');
            $table->string('estado_pago');
            $table->string('medio_pago')->nullable();
            $table->string('moneda')->default('ARS');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('costo_envio', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};

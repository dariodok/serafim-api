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
        Schema::create('comprobantes_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->foreignId('datos_facturacion_id')->nullable()->constrained('datos_facturacion');
            $table->string('tipo_comprobante');
            $table->string('punto_venta');
            $table->string('numero_comprobante')->nullable();
            $table->timestamp('fecha_emision')->nullable();
            $table->string('estado');
            $table->string('razon_social')->nullable();
            $table->string('nombre_completo')->nullable();
            $table->string('tipo_documento')->nullable();
            $table->string('numero_documento')->nullable();
            $table->string('cuit')->nullable();
            $table->string('condicion_iva');
            $table->string('email_facturacion')->nullable();
            $table->string('domicilio_fiscal');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('importe_iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('cae')->nullable();
            $table->timestamp('cae_vencimiento')->nullable();
            $table->json('respuesta_externa')->nullable();
            $table->timestamps();

            $table->unique(['tipo_comprobante', 'punto_venta', 'numero_comprobante'], 'comprobante_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comprobantes_facturacion');
    }
};

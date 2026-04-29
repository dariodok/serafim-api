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
        Schema::create('envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->foreignId('domicilio_id')->nullable()->constrained('domicilios');
            $table->string('proveedor');
            $table->string('servicio')->nullable();
            $table->string('estado');
            $table->text('motivo_estado')->nullable();
            $table->string('referencia_externa')->nullable();
            $table->string('codigo_seguimiento')->nullable();
            $table->string('codigo_bulto')->nullable();
            $table->string('url_etiqueta')->nullable();
            $table->string('archivo_etiqueta')->nullable();
            $table->decimal('costo_envio', 12, 2)->nullable();
            $table->string('moneda')->default('ARS');
            $table->unsignedInteger('peso_gramos')->nullable();
            $table->decimal('alto_cm', 8, 2)->nullable();
            $table->decimal('ancho_cm', 8, 2)->nullable();
            $table->decimal('largo_cm', 8, 2)->nullable();
            $table->string('destinatario');
            $table->string('telefono')->nullable();
            $table->string('provincia');
            $table->string('localidad');
            $table->string('codigo_postal');
            $table->string('calle');
            $table->string('numero');
            $table->string('piso')->nullable();
            $table->string('departamento')->nullable();
            $table->text('referencia')->nullable();
            $table->timestamp('fecha_generacion')->nullable();
            $table->timestamp('fecha_cancelacion')->nullable();
            $table->timestamp('fecha_despacho')->nullable();
            $table->timestamp('fecha_entrega')->nullable();
            $table->json('respuesta_ultima_api')->nullable();
            $table->json('datos_adicionales')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('envios');
    }
};

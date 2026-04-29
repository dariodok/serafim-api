<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_bultos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero_bulto');
            $table->string('estado')->default('pendiente');
            $table->string('referencia_externa')->nullable();
            $table->string('codigo_seguimiento')->nullable();
            $table->string('codigo_bulto')->nullable();
            $table->string('url_etiqueta')->nullable();
            $table->string('archivo_etiqueta')->nullable();
            $table->string('formato_etiqueta')->nullable();
            $table->decimal('valor_declarado', 12, 2)->nullable();
            $table->unsignedInteger('peso_gramos')->nullable();
            $table->decimal('alto_cm', 8, 2)->nullable();
            $table->decimal('ancho_cm', 8, 2)->nullable();
            $table->decimal('largo_cm', 8, 2)->nullable();
            $table->json('respuesta_ultima_api')->nullable();
            $table->json('datos_adicionales')->nullable();
            $table->timestamps();

            $table->unique(['envio_id', 'numero_bulto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_bultos');
    }
};

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
        Schema::create('afip_consultas_fiscales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('datos_facturacion_id')->nullable()->constrained('datos_facturacion')->nullOnDelete();
            $table->string('documento_consultado', 20)->nullable()->index();
            $table->string('nombre_buscado')->nullable();
            $table->string('cuit_consultado', 20)->nullable()->index();
            $table->string('id_persona_seleccionada', 20)->nullable()->index();
            $table->string('estado_resultado', 30)->default('ok')->index();
            $table->json('candidatos')->nullable();
            $table->json('seleccion')->nullable();
            $table->json('domicilios')->nullable();
            $table->json('actividades')->nullable();
            $table->json('impuestos')->nullable();
            $table->json('regimenes')->nullable();
            $table->json('categorias')->nullable();
            $table->json('caracterizaciones')->nullable();
            $table->json('relaciones')->nullable();
            $table->json('a13_raw')->nullable();
            $table->json('constancia_raw')->nullable();
            $table->json('resultado_normalizado')->nullable();
            $table->json('errores')->nullable();
            $table->timestamp('consultado_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afip_consultas_fiscales');
    }
};

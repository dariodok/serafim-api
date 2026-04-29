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
        Schema::create('datos_facturacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('alias')->nullable();
            $table->string('tipo_persona');
            $table->string('razon_social')->nullable();
            $table->string('nombre_completo')->nullable();
            $table->string('tipo_documento')->nullable();
            $table->string('numero_documento')->nullable();
            $table->string('cuit')->nullable();
            $table->string('condicion_iva');
            $table->string('email_facturacion')->nullable();
            $table->string('provincia');
            $table->string('localidad');
            $table->string('codigo_postal');
            $table->string('calle');
            $table->string('numero');
            $table->string('piso')->nullable();
            $table->string('departamento')->nullable();
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
        Schema::dropIfExists('datos_facturacion');
    }
};

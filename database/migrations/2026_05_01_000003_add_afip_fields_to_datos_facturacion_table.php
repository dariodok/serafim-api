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
        Schema::table('datos_facturacion', function (Blueprint $table) {
            $table->string('afip_id_persona', 20)->nullable()->after('cuit')->index();
            $table->unsignedSmallInteger('condicion_iva_receptor_id')->nullable()->after('condicion_iva');
            $table->string('afip_estado_clave', 50)->nullable()->after('condicion_iva_receptor_id');
            $table->timestamp('afip_ultima_consulta_at')->nullable()->after('afip_estado_clave');
            $table->json('afip_datos')->nullable()->after('afip_ultima_consulta_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('datos_facturacion', function (Blueprint $table) {
            $table->dropColumn([
                'afip_id_persona',
                'condicion_iva_receptor_id',
                'afip_estado_clave',
                'afip_ultima_consulta_at',
                'afip_datos',
            ]);
        });
    }
};

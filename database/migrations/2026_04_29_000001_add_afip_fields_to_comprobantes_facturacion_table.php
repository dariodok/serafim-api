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
        Schema::table('comprobantes_facturacion', function (Blueprint $table) {
            $table->foreignId('comprobante_asociado_id')
                ->nullable()
                ->after('datos_facturacion_id')
                ->constrained('comprobantes_facturacion');
            $table->unsignedSmallInteger('codigo_tipo_comprobante')->nullable()->after('tipo_comprobante');
            $table->string('ambiente')->nullable()->after('estado');
            $table->unsignedSmallInteger('codigo_tipo_documento')->nullable()->after('tipo_documento');
            $table->unsignedSmallInteger('condicion_iva_receptor_id')->nullable()->after('condicion_iva');
            $table->string('moneda', 10)->default('PES')->after('total');
            $table->decimal('moneda_cotizacion', 12, 6)->default(1)->after('moneda');
            $table->json('qr_payload')->nullable()->after('cae_vencimiento');
            $table->text('qr_url')->nullable()->after('qr_payload');
            $table->json('solicitud_externa')->nullable()->after('qr_url');
            $table->json('observaciones_afip')->nullable()->after('respuesta_externa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comprobantes_facturacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comprobante_asociado_id');
            $table->dropColumn([
                'codigo_tipo_comprobante',
                'ambiente',
                'codigo_tipo_documento',
                'condicion_iva_receptor_id',
                'moneda',
                'moneda_cotizacion',
                'qr_payload',
                'qr_url',
                'solicitud_externa',
                'observaciones_afip',
            ]);
        });
    }
};

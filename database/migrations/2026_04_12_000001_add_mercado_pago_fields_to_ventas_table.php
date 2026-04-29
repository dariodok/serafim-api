<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('mercado_pago_preference_id')->nullable()->after('observaciones');
            $table->string('mercado_pago_external_reference')->nullable()->unique()->after('mercado_pago_preference_id');
            $table->text('mercado_pago_init_point')->nullable()->after('mercado_pago_external_reference');
            $table->text('mercado_pago_sandbox_init_point')->nullable()->after('mercado_pago_init_point');
            $table->string('mercado_pago_checkout_status')->nullable()->after('mercado_pago_sandbox_init_point');
            $table->timestamp('mercado_pago_last_sync_at')->nullable()->after('mercado_pago_checkout_status');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['mercado_pago_external_reference']);
            $table->dropColumn([
                'mercado_pago_preference_id',
                'mercado_pago_external_reference',
                'mercado_pago_init_point',
                'mercado_pago_sandbox_init_point',
                'mercado_pago_checkout_status',
                'mercado_pago_last_sync_at',
            ]);
        });
    }
};

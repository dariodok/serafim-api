<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envio_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('envios')->cascadeOnDelete();
            $table->string('estado');
            $table->string('origen')->default('interno');
            $table->text('descripcion')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('ocurrio_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envio_eventos');
    }
};

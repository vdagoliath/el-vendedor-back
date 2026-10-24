<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de cada serie. El servidor NUNCA renumera: solo lleva la cuenta de
     * lo que ya emitieron los equipos, para poder auditar y para detectar huecos
     * (`gaps`) cuando una reserva no llego a emitirse.
     */
    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('series', 24);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedSmallInteger('padding')->default(8);
            $table->unsignedBigInteger('last_number')->nullable();
            $table->string('last_invoice_external_id', 191)->nullable();
            $table->timestamp('last_issued_at')->nullable();
            $table->json('gaps')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'series']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};

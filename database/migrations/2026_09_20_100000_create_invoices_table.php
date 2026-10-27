<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documento de facturacion. Es inmutable: guarda una copia congelada del
     * emisor, el receptor, las lineas y el total, y no se renumera nunca al
     * sincronizar. Contrato: docs/facturacion/contrato-factura-v1.md del cliente
     * de escritorio.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // `external_id` es `invoice:<serie>:<numero con relleno>`.
            $table->string('external_id', 191);
            $table->unsignedSmallInteger('contract_version')->default(1);
            $table->string('document_type', 32)->default('invoice'); // invoice | credit_note
            $table->string('series', 24);
            $table->unsignedBigInteger('number');
            $table->unsignedSmallInteger('number_padding')->default(8);
            $table->string('display_number', 64);
            $table->string('number_source', 24)->default('local'); // local | server_block
            $table->string('sale_external_id', 191)->nullable();
            $table->string('original_invoice_external_id', 191)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('status', 24)->default('issued'); // issued | voided
            $table->string('currency', 8)->nullable();
            $table->json('issuer')->nullable();
            $table->json('customer')->nullable();
            $table->json('totals')->nullable();
            $table->json('payment')->nullable();
            $table->string('hash', 191)->nullable();
            $table->unsignedInteger('reprint_count')->default(0);
            $table->text('footer')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('voided_by')->nullable();
            $table->string('void_reason', 191)->nullable();
            $table->json('created_by')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            // Regla del contrato: un numero no se repite dentro de una serie.
            $table->unique(['business_id', 'series', 'number'], 'invoices_business_series_number_unique');
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'sale_external_id']);
            $table->index(['business_id', 'issued_at']);
            $table->index('server_version', 'invoices_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

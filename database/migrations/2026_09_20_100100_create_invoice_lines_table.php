<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lineas del documento. Se reemplazan completas en cada aplicacion: una
     * factura no se edita linea a linea, se vuelve a materializar tal cual llega.
     * Sin impuestos ni descuentos: `line_total = quantity * unit_price`.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('product_external_id', 191)->nullable();
            $table->string('product_title')->nullable();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->decimal('unit_price', 16, 4)->default(0);
            $table->decimal('line_total', 16, 4)->default(0);
            $table->string('unit_symbol', 32)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('invoice_id', 'invoice_lines_invoice_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};

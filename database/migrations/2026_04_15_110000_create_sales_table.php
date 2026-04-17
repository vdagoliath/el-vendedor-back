<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('reference')->nullable();
            $table->string('contact_external_id', 191)->nullable();
            $table->string('pos_external_id', 191)->nullable();
            $table->string('warehouse_external_id', 191)->nullable();
            $table->string('cash_register_session_id', 191)->nullable();
            $table->decimal('total', 16, 2)->default(0);
            $table->string('status', 32)->default('completed');
            $table->string('currency', 8)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->decimal('amount_received', 16, 2)->nullable();
            $table->decimal('change_amount', 16, 2)->nullable();
            $table->json('cash_breakdown')->nullable();
            $table->json('card_payment_details')->nullable();
            $table->json('created_by')->nullable();
            $table->json('inventory_consumption')->nullable();
            $table->string('sale_id_import', 191)->nullable();
            $table->json('items_imported')->nullable();
            $table->timestamp('transaction_at')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'contact_external_id']);
            $table->index(['business_id', 'transaction_at']);
        });

        Schema::create('sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('product_external_id', 191)->nullable();
            $table->string('product_title')->nullable();
            $table->decimal('price', 16, 4)->default(0);
            $table->decimal('amount', 16, 4)->default(0);
            $table->decimal('sub_total', 16, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};

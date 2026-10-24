<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('reference')->nullable();
            $table->string('contact_external_id', 191)->nullable();
            $table->string('warehouse_external_id', 191)->nullable();
            $table->decimal('total', 16, 2)->default(0);
            $table->string('status', 32)->default('completed');
            $table->string('currency', 8)->nullable();
            $table->json('created_by')->nullable();
            $table->json('inventory_consumption')->nullable();
            $table->timestamp('transaction_at')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'transaction_at']);
        });

        Schema::create('purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
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
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->string('batch_code', 191)->nullable();
            $table->decimal('quantity', 16, 4);
            $table->decimal('remaining_quantity', 16, 4);
            $table->date('expiration_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('source', 64)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'product_external_id', 'warehouse_external_id']);
            $table->index(['business_id', 'warehouse_external_id', 'expiration_date']);
            $table->index('server_version', 'product_batches_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_losses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable()->after('id');
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('product_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->decimal('quantity', 16, 4);
            $table->string('loss_type', 32)->default('other');
            $table->text('notes')->nullable();
            $table->longText('photo')->nullable();
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->decimal('previous_quantity', 16, 4)->nullable();
            $table->timestamp('loss_at')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'product_external_id']);
            $table->index('server_version', 'product_losses_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_losses');
    }
};

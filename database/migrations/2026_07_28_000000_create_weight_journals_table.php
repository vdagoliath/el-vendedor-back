<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_version')->nullable()->after('id');
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('status', 24)->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('pos_external_id', 191);
            $table->string('pos_name')->nullable();
            $table->string('cash_register_session_external_id', 191);
            $table->string('warehouse_external_id', 191);
            $table->string('payment_method', 32)->nullable();
            $table->json('items')->nullable();
            $table->decimal('total_sold_quantity', 16, 4)->default(0);
            $table->decimal('total_loss_quantity', 16, 4)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('sale_external_id', 191)->nullable();
            $table->string('sale_reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
            $table->index(['business_id', 'cash_register_session_external_id', 'status'], 'weight_journals_session_status_index');
            $table->index(['business_id', 'warehouse_external_id']);
            $table->index('server_version', 'weight_journals_server_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_journals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_received_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 191)->nullable();
            $table->string('event_id', 191);
            $table->string('entity_type');
            $table->string('entity_id', 191);
            $table->string('operation');
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'event_id']);
            $table->index(['business_id', 'device_id']);
            $table->index(['business_id', 'entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_received_events');
    }
};

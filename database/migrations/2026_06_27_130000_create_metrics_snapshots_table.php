<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->unsignedBigInteger('server_version')->nullable()->index();
            $table->string('period')->default('day');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('last_received_event_id')->nullable();
            $table->json('source_runs')->nullable();
            $table->json('source_counts')->nullable();
            $table->json('totals')->nullable();
            $table->json('products')->nullable();
            $table->json('expense_categories')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'period_start']);
            $table->index(['business_id', 'updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_snapshots');
    }
};

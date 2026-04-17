<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

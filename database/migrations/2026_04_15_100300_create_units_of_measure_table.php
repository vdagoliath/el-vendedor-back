<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('name');
            $table->string('symbol', 16);
            $table->string('category', 64)->nullable();
            $table->decimal('ratio', 16, 6)->default(1);
            $table->boolean('is_reference')->default(false);
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
        Schema::dropIfExists('units_of_measure');
    }
};

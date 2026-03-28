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
        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 191)->nullable();
            $table->string('last_pushed_cursor')->nullable();
            $table->string('last_pulled_cursor')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_checkpoints');
    }
};

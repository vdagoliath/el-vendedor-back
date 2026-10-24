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
        Schema::create('sync_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id');
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 191)->nullable();
            $table->string('stage', 50);
            $table->string('route_name', 120)->nullable();
            $table->string('status', 40);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->string('client_app_version', 100)->nullable();
            $table->string('client_sync_version', 20)->nullable();
            $table->string('server_app_version', 100)->nullable();
            $table->unsignedInteger('server_sync_version')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('response_meta')->nullable();
            $table->timestamps();

            $table->unique('request_id');
            $table->index(['stage', 'status']);
            $table->index(['business_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_diagnostics');
    }
};

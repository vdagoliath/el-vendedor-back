<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employee_external_id', 191);
            $table->string('employee_name')->nullable();
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_device_uuid', 191)->nullable();
            $table->unsignedBigInteger('issued_token_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'employee_external_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_invitations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_sequences', function (Blueprint $table): void {
            $table->foreignId('business_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_sequences');
    }
};

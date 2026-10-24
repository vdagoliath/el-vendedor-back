<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('credit_balance', 16, 2)->nullable()->after('payment_method');
            $table->json('payment_breakdown')->nullable()->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['credit_balance', 'payment_breakdown']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_journals', function (Blueprint $table): void {
            $table->json('payment_breakdown')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('weight_journals', function (Blueprint $table): void {
            $table->dropColumn('payment_breakdown');
        });
    }
};

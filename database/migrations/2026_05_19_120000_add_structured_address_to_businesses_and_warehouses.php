<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('country', 8)->nullable()->after('address');
            $table->string('province')->nullable()->after('country');
            $table->string('municipality')->nullable()->after('province');
            $table->string('street')->nullable()->after('municipality');
        });

        // Backfill street ← address so existing data is not lost while
        // the legacy column remains in place for any other readers.
        DB::table('businesses')
            ->whereNotNull('address')
            ->update([
                'country' => 'CU',
                'street' => DB::raw('address'),
            ]);

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('country', 8)->nullable()->after('name');
            $table->string('province')->nullable()->after('country');
            $table->string('municipality')->nullable()->after('province');
            $table->string('street')->nullable()->after('municipality');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn(['country', 'province', 'municipality', 'street']);
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['country', 'province', 'municipality', 'street']);
        });
    }
};

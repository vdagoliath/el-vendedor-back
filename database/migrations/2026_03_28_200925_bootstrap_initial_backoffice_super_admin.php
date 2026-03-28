<?php

use App\Enums\BackofficeRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasBackofficeUsers = DB::table('users')
            ->whereNotNull('backoffice_role')
            ->orWhere('is_platform_admin', true)
            ->exists();

        if ($hasBackofficeUsers) {
            return;
        }

        $firstUserId = DB::table('users')
            ->orderBy('id')
            ->value('id');

        if (! $firstUserId) {
            return;
        }

        DB::table('users')
            ->where('id', $firstUserId)
            ->update([
                'backoffice_role' => BackofficeRole::SuperAdmin->value,
                'is_platform_admin' => true,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $firstUserId = DB::table('users')
            ->where('backoffice_role', BackofficeRole::SuperAdmin->value)
            ->orderBy('id')
            ->value('id');

        if (! $firstUserId) {
            return;
        }

        DB::table('users')
            ->where('id', $firstUserId)
            ->update([
                'backoffice_role' => null,
                'is_platform_admin' => false,
                'updated_at' => now(),
            ]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_product_publications', 'server_version')) {
                $table->unsignedBigInteger('server_version')->nullable()->after('id');
                $table->index('server_version', 'marketplace_publications_server_version_index');
            }
        });

        $this->backfillServerVersions();
    }

    public function down(): void
    {
        Schema::table('marketplace_product_publications', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_product_publications', 'server_version')) {
                $table->dropIndex('marketplace_publications_server_version_index');
                $table->dropColumn('server_version');
            }
        });
    }

    private function backfillServerVersions(): void
    {
        if (! Schema::hasTable('business_sequences')) {
            return;
        }

        DB::table('marketplace_product_publications')
            ->whereNull('server_version')
            ->orderBy('business_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['id', 'business_id'])
            ->each(function ($publication): void {
                $sequence = DB::table('business_sequences')
                    ->where('business_id', $publication->business_id)
                    ->lockForUpdate()
                    ->first();

                $nextVersion = ((int) ($sequence->last_version ?? 0)) + 1;

                DB::table('business_sequences')->updateOrInsert(
                    ['business_id' => $publication->business_id],
                    [
                        'last_version' => $nextVersion,
                        'updated_at' => now(),
                        'created_at' => $sequence->created_at ?? now(),
                    ]
                );

                DB::table('marketplace_product_publications')
                    ->where('id', $publication->id)
                    ->update(['server_version' => $nextVersion]);
            });
    }
};

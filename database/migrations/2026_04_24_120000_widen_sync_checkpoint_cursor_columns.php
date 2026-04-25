<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pull cursor is a serialized JSON bundle that grows with each new
     * synchronizable entity, so the previous VARCHAR(255) cap is no longer
     * sufficient under PostgreSQL (SQLSTATE 22001).
     */
    public function up(): void
    {
        Schema::table('sync_checkpoints', function (Blueprint $table) {
            $table->text('last_pushed_cursor')->nullable()->change();
            $table->text('last_pulled_cursor')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_checkpoints', function (Blueprint $table) {
            $table->string('last_pushed_cursor')->nullable()->change();
            $table->string('last_pulled_cursor')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('pos_external_id', 191);
            $table->string('warehouse_external_id', 191)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->decimal('closing_balance', 16, 2)->nullable();
            $table->json('opened_by')->nullable();
            $table->json('closed_by')->nullable();
            $table->json('initial_inventory_snapshot')->nullable();
            $table->json('final_inventory_snapshot')->nullable();
            $table->string('last_received_event_id', 191)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'external_id']);
            $table->index(['business_id', 'pos_external_id', 'status']);
            $table->index(['business_id', 'updated_at']);
        });

        // Índice parcial único: una sola sesión 'open' por (business, pos) cuando no está borrada.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX cash_register_sessions_one_open_per_pos '
                .'ON cash_register_sessions (business_id, pos_external_id) '
                ."WHERE status = 'open' AND deleted_at IS NULL"
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL no soporta índices parciales; emulamos con columna generada.
            DB::statement(
                'ALTER TABLE cash_register_sessions '
                ."ADD COLUMN open_pos_lock VARCHAR(191) AS (CASE WHEN status = 'open' AND deleted_at IS NULL THEN CONCAT(business_id, ':', pos_external_id) ELSE NULL END) STORED, "
                .'ADD UNIQUE INDEX cash_register_sessions_one_open_per_pos (open_pos_lock)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};

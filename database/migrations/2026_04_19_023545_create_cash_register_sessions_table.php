<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'cash_register_sessions';

    private const ONE_OPEN_PER_POS_INDEX = 'cash_register_sessions_one_open_per_pos';

    public function up(): void
    {
        // Idempotente: en el deploy que falló, la tabla quedó creada pero la
        // migración no se registró, así que el siguiente intento la encuentra.
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
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
        }

        $this->ensureOneOpenSessionPerPosIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * Índice parcial único: una sola sesión 'open' por (business, pos) cuando
     * no está borrada.
     */
    private function ensureOneOpenSessionPerPosIndex(): void
    {
        if (Schema::hasIndex(self::TABLE, self::ONE_OPEN_PER_POS_INDEX)) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::ONE_OPEN_PER_POS_INDEX.' '
                .'ON '.self::TABLE.' (business_id, pos_external_id) '
                ."WHERE status = 'open' AND deleted_at IS NULL"
            );

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL no soporta índices parciales; emulamos con columna generada.
            // Tiene que ser VIRTUAL y no STORED: MySQL rechaza que una columna
            // STORED dependa de una columna con FK ON DELETE CASCADE (aquí
            // business_id la tiene) y el ALTER falla con "1215 Cannot add
            // foreign key constraint" al recrear la FK.
            //
            // 212 = 20 dígitos de BIGINT UNSIGNED + ':' + 191 de pos_external_id.
            // Con 191 el lock se truncaba (error 1406) con pos_external_id largos.
            $addColumn = Schema::hasColumn(self::TABLE, 'open_pos_lock')
                ? ''
                : "ADD COLUMN open_pos_lock VARCHAR(212) AS (CASE WHEN status = 'open' AND deleted_at IS NULL THEN CONCAT(business_id, ':', pos_external_id) ELSE NULL END) VIRTUAL, ";

            DB::statement(
                'ALTER TABLE '.self::TABLE.' '.$addColumn
                .'ADD UNIQUE INDEX '.self::ONE_OPEN_PER_POS_INDEX.' (open_pos_lock)'
            );
        }
    }
};

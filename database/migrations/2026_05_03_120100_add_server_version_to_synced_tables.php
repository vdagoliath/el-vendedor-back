<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade `server_version BIGINT NULL` a todas las entidades materializadas
 * que el cliente jala vía `/sync/pull`. Esta versión es asignada por el
 * `ServerVersionAllocator` (estricta y monotónica por negocio) cada vez
 * que la fila muta. El cursor de pull migrará a `cursor=v3:<N>` que
 * filtra por `server_version > N`.
 *
 * Backfill: por cada negocio recorremos las filas existentes en orden de
 * `updated_at` y les asignamos versiones secuenciales. Esto deja a las
 * filas que ya estaban en la base con un orden razonable; nuevas
 * mutaciones tomarán versiones siguientes.
 */
return new class extends Migration
{
    /**
     * Tabla → expresión del business_id que la fila lleva. Para
     * `businesses` la propia clave primaria ES el business.
     *
     * @var array<string, string>
     */
    private array $tables = [
        'businesses' => 'id',
        'categories' => 'business_id',
        'units_of_measure' => 'business_id',
        'employees' => 'business_id',
        'warehouses' => 'business_id',
        'points_of_sale' => 'business_id',
        'cash_register_sessions' => 'business_id',
        'contacts' => 'business_id',
        'products' => 'business_id',
        'stock_movements' => 'business_id',
        'stock_adjustments' => 'business_id',
        'sales' => 'business_id',
        'purchases' => 'business_id',
        'expenses' => 'business_id',
    ];

    public function up(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unsignedBigInteger('server_version')->nullable()->after('id');
                $blueprint->index(['server_version'], $table.'_server_version_index');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_server_version_index');
                $blueprint->dropColumn('server_version');
            });
        }
    }

    private function backfill(): void
    {
        $businessIds = DB::table('businesses')->pluck('id');

        foreach ($businessIds as $businessId) {
            $this->backfillBusiness((int) $businessId);
        }
    }

    private function backfillBusiness(int $businessId): void
    {
        // Recolecta tuplas (table, id, updated_at) de cada tabla del negocio
        // y las ordena globalmente por updated_at. El orden es aproximado
        // (los relojes pueden no coincidir entre filas creadas en
        // dispositivos distintos), pero alcanza para sembrar el sequence.
        $candidates = collect();

        foreach ($this->tables as $table => $businessColumn) {
            $rows = DB::table($table)
                ->select(['id', 'updated_at'])
                ->where($businessColumn, $businessId)
                ->orderBy('updated_at')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $candidates->push([
                    'table' => $table,
                    'id' => (int) $row->id,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }

        $sorted = $candidates->sortBy([
            ['updated_at', 'asc'],
            ['table', 'asc'],
            ['id', 'asc'],
        ])->values();

        $version = 0;
        foreach ($sorted as $entry) {
            $version++;
            DB::table($entry['table'])
                ->where('id', $entry['id'])
                ->update(['server_version' => $version]);
        }

        DB::table('business_sequences')->updateOrInsert(
            ['business_id' => $businessId],
            [
                'last_version' => $version,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
};

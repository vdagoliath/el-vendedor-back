<?php

namespace App\Support\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Asignador de `server_version` monotónico por negocio.
 *
 * Cada llamada a `next($businessId)` corre dentro de una transacción que
 * bloquea la fila correspondiente en `business_sequences` con
 * `lockForUpdate()`. Esto serializa pushes concurrentes contra el mismo
 * negocio y garantiza que las versiones sean estrictamente crecientes.
 *
 * Diseño:
 *   - El allocator NO conoce el modelo del que viene la mutación. Sólo
 *     entrega un entero. La integración se hace por trait/observer en
 *     cada modelo materializado.
 *   - Si un negocio aún no tiene fila en `business_sequences`, la
 *     creamos con `last_version=0` antes de incrementar.
 *   - `nextRange($businessId, $count)` reserva un bloque contiguo de
 *     versiones para escenarios donde un mismo push materializa varias
 *     filas y queremos minimizar transacciones.
 */
class ServerVersionAllocator
{
    /**
     * Reserva el próximo número de versión para un negocio. Devuelve un
     * entero estrictamente mayor que cualquier versión previamente
     * entregada para ese mismo negocio.
     */
    public function next(int $businessId): int
    {
        return $this->reserve($businessId, 1)[0];
    }

    /**
     * Reserva `$count` versiones contiguas y devuelve los enteros en
     * orden ascendente. Util cuando un applier muta varias filas en una
     * misma operación.
     *
     * @return array<int, int>
     */
    public function nextRange(int $businessId, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return $this->reserve($businessId, $count);
    }

    /**
     * @return array<int, int>
     */
    private function reserve(int $businessId, int $count): array
    {
        return DB::transaction(function () use ($businessId, $count): array {
            $row = DB::table('business_sequences')
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            $current = $row ? (int) $row->last_version : 0;
            $next = $current + 1;
            $newCurrent = $current + $count;

            if ($row) {
                DB::table('business_sequences')
                    ->where('business_id', $businessId)
                    ->update([
                        'last_version' => $newCurrent,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('business_sequences')->insert([
                    'business_id' => $businessId,
                    'last_version' => $newCurrent,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return range($next, $newCurrent);
        });
    }
}

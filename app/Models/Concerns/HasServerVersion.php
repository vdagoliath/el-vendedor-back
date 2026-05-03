<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Support\Sync\ServerVersionAllocator;

/**
 * Trait que reserva una `server_version` monotónica por negocio cada vez
 * que el modelo se guarda con cambios "reales". Aplicar a cada tabla
 * materializada que el cliente jala vía `/sync/pull`.
 *
 * Reglas de cuándo asignamos:
 *   - El modelo es nuevo (no existe en DB todavía).
 *   - El modelo tiene atributos sucios distintos a la propia
 *     `server_version` (filtramos para evitar ciclos infinitos).
 *
 * El método `serverVersionBusinessId()` permite a modelos como
 * `Business` (donde la pk ES el business_id) sobreescribir la fuente
 * del business_id. Por defecto usa la columna `business_id`.
 */
trait HasServerVersion
{
    public static function bootHasServerVersion(): void
    {
        static::saving(function ($model): void {
            if (! $model->shouldAllocateServerVersion()) {
                return;
            }

            $businessId = $model->serverVersionBusinessId();
            if (! $businessId) {
                return;
            }

            /** @var ServerVersionAllocator $allocator */
            $allocator = app(ServerVersionAllocator::class);
            $model->server_version = $allocator->next((int) $businessId);
        });
    }

    public function shouldAllocateServerVersion(): bool
    {
        if (! $this->exists) {
            return true;
        }

        $dirty = $this->getDirty();
        unset($dirty['server_version']);

        return ! empty($dirty);
    }

    /**
     * El business_id desde el que reservar la versión. Modelos cuyo
     * propio id es el business (e.g. `Business`) deben sobreescribir.
     */
    public function serverVersionBusinessId(): ?int
    {
        $value = $this->getAttribute('business_id');

        return $value === null ? null : (int) $value;
    }
}

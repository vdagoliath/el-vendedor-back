# Sync API V1

## Objetivo

Este documento describe la primera base ejecutable del contrato de sincronizacion offline-first para `el-vendedor-back`.

## Endpoints iniciales

### GET `/api/v1/sync/bootstrap`

Devuelve:

- version de sync
- negocio actual
- capacidades soportadas
- entidades previstas
- limites del contrato

### GET `/api/v1/sync/pull`

Parametros:

- `device_id` requerido
- `cursor` opcional
- `limit` opcional

Comportamiento actual:

- registra o refresca el dispositivo
- actualiza checkpoint de pull
- devuelve deltas reales de `products`
- devuelve conflictos abiertos asociados al negocio
- soporta `has_more` con cursor incremental

### POST `/api/v1/sync/push`

Payload:

```json
{
  "device": {
    "id": "device-uuid-or-stable-id",
    "name": "Samsung A15",
    "platform": "android",
    "app_version": "1.2.3"
  },
  "cursor": "2026-03-25T18:00:00Z",
  "changes": [
    {
      "event_id": "evt_001",
      "entity_type": "products",
      "entity_id": "prod_123",
      "operation": "upsert",
      "occurred_at": "2026-03-25T18:05:00Z",
      "payload": {
        "title": "Arroz 1kg"
      }
    }
  ]
}
```

Comportamiento actual:

- registra o refresca el dispositivo
- guarda cada evento recibido en `sync_received_events`
- aplica idempotencia por `business_id + event_id`
- aplica eventos de `products` al dominio real
- responde con listas de `accepted`, `duplicates` y `rejected`
- actualiza checkpoint de push

## Tablas base

### `devices`

Representa instalaciones o clientes que sincronizan contra un negocio.

### `sync_received_events`

Recibe eventos del cliente y sirve como capa de idempotencia y staging antes de aplicar al dominio definitivo.

### `sync_conflicts`

Guarda conflictos no resolubles automaticamente.

### `sync_checkpoints`

Mantiene el estado de cursores y la ultima actividad de sync por dispositivo y negocio.

### `products`

Primera tabla de dominio sincronizada de forma real. Guarda:

- `external_id` del cliente offline
- `code` unico por negocio a nivel de regla de aplicacion
- precios, nombre, descripcion y tipo
- unidad de medida y stock snapshot en JSON como compatibilidad transitoria
- `deleted_at` para tombstones y `pull` incremental

## Slice ejecutable actual

Esta version ya permite:

1. recibir `create`, `update`, `upsert` y `delete` de `products`
2. aplicar esos cambios a la tabla `products`
3. detectar conflicto basico por codigo duplicado
4. registrar el conflicto en `sync_conflicts`
5. devolver cambios de `products` por `pull`

## Siguiente iteracion recomendada

1. Separar `categories`, `units` y `warehouses` en tablas propias.
2. Incorporar `inventory_events` para dejar de depender de `stock_by_warehouse` como snapshot transitorio.
3. Agregar aplicacion real de `sales`, `purchases` y `expenses`.
4. Implementar resolucion operativa de conflictos desde cliente o panel administrativo.
5. Hacer que la app movil aplique automaticamente los deltas de `pull` sobre la base local.

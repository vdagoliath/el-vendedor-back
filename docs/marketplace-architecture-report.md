# Evolucion de ElVendedor hacia Marketplace

Fecha: 2026-08-01

## Alcance de esta fase

Este informe analiza el backend Laravel existente y define una ruta de evolucion hacia una plataforma Marketplace sin crear un segundo backend, sin mover codigo innecesariamente y reutilizando la logica operativa actual de gestion, inventario, ventas y sincronizacion.

No se implementa codigo de Marketplace en esta fase. La prioridad es acordar la arquitectura, los limites de modulo, las entidades principales y el plan de migracion.

## Inventario del sistema actual

### Modelos principales

El dominio actual gira alrededor de negocios, usuarios, catalogo, inventario, operaciones comerciales y sincronizacion offline.

Modelos de identidad y negocio:

- `User`: usuario autenticado, roles de backoffice y relacion con negocios.
- `Business`: negocio vendedor, politicas, datos fiscales/perfil, licencia y relacion con usuarios.
- `BusinessSequence`: secuencias por negocio.
- `PersonalAccessToken`: token Sanctum extendido con negocio y capacidades de sync.
- `SellerInvitation`: invitaciones para vendedores/dispositivos.
- `Device`: dispositivo sincronizado.

Modelos de catalogo e inventario:

- `Product`: producto del negocio, identificado por `business_id` + `external_id`, con precio, categoria, unidad, receta/desglose y snapshot legacy `stock_by_warehouse`.
- `Category`, `UnitOfMeasure`, `Warehouse`, `PointOfSale`.
- `StockProjection`: proyeccion materializada de stock por `business_id`, `product_external_id` y `warehouse_external_id`.
- `StockMovement`: movimiento entre almacenes.
- `StockAdjustment`: ajuste manual de stock.
- `ProductLoss`: merma.
- `ProductBreakdown`: conversion/desglose entre productos.
- `ProductBatch`: lote de producto.

Modelos comerciales:

- `Sale` y `SaleLine`: venta materializada desde Sync, con estado, almacen, POS, contacto, total, lineas e `inventory_consumption`.
- `Purchase` y `PurchaseLine`: compra materializada desde Sync.
- `Expense`: gasto.
- `Contact`: clientes/proveedores.
- `Employee`: empleados sincronizados.
- `CashRegisterSession`: sesiones de caja por POS con lease maestro.
- `MetricsSnapshot`: snapshots analiticos.

Modelos de sincronizacion:

- `SyncReceivedEvent`: log de eventos recibidos desde dispositivos y eventos de servidor.
- `SyncConflict`: conflictos registrados durante push.
- `SyncCheckpoint`: cursor por dispositivo.
- `SyncDiagnostic`: diagnosticos de sincronizacion.

### Relaciones relevantes

- `Business` tiene muchos usuarios por pivot `business_user`; `User` pertenece a un `currentBusiness`.
- Los modelos sincronizados pertenecen a `Business` y usan `external_id` como identificador estable entre cliente offline y servidor.
- `Sale` tiene muchas `SaleLine`; `Purchase` tiene muchas `PurchaseLine`.
- `StockProjection` no tiene relacion Eloquent directa con `Product` o `Warehouse`, pero enlaza por `product_external_id` y `warehouse_external_id` dentro del mismo negocio.
- `SyncReceivedEvent` pertenece a `Business` y opcionalmente a `User`.

### Servicios y soportes existentes

- `App\Support\Inventory\InventoryProjector`: servicio central actual para mutar `stock_projections` mediante deltas o seeds. Usa transacciones y `lockForUpdate()` sobre la fila de proyeccion.
- `App\Support\Sync\SyncTransactionApplier`: materializa ventas, compras, gastos, movimientos, ajustes, mermas y desgloses; invoca `InventoryProjector` para modificar stock.
- `App\Support\Sync\SyncEntityApplier`: materializa entidades maestras como categorias, contactos, empleados, unidades, almacenes, POS, lotes y metricas.
- `App\Support\Sync\SyncEventReprocessor`: reprocesa eventos fallidos.
- `App\Support\Sync\SyncCompatibility`: evalua version de cliente y politicas de compatibilidad.
- `App\Support\Sync\BusinessPolicies`, `SyncCursor`, `ServerVersionAllocator`, `SyncDiagnosticsRecorder`: politicas y soporte de sync.
- `App\Support\Backoffice\CurrentBusinessSyncStore`: lee y escribe eventos de servidor para backoffice.
- Exportadores Excel de inventario, ventas, movimientos, ajustes y mermas.
- `BusinessCleaner` para limpiar datos de negocio.
- `BusinessLicensePricingResolver` para precios/licencia.

### Controladores y APIs

APIs de gestion/sync:

- `routes/api.php` agrupa `api.v1.auth`, `api.v1.sync` y `api.v1.cash-register`.
- `SyncPushController` recibe cambios offline, deduplica por `event_id`, materializa entidades y registra conflictos.
- `SyncPullController` entrega datos materializados al cliente.
- `SyncBootstrapController` prepara bootstrap inicial.
- `ReprocessFailedEventsController` permite reprocesar eventos fallidos.
- Controladores de auth API manejan registro, login, usuario actual, negocio actual e invitaciones de vendedor.

Backoffice Inertia:

- `routes/backoffice.php` cubre negocios, productos, ventas, compras, inventario, movimientos, ajustes, mermas, POS, gastos y equipo.
- Controladores de inventario leen `StockProjection`.
- Controladores de ventas/compras todavia leen el ultimo payload de `SyncReceivedEvent` via `CurrentBusinessSyncStore` para algunas vistas y cambios de estado.

### Comandos

- `inventory:rebuild` reconstruye proyecciones de stock.
- `sync:prune-received-events` limpia eventos recibidos antiguos.

### Eventos, jobs, politicas y middleware

- No se observan jobs de dominio propios ni listeners/eventos de aplicacion para Marketplace.
- La autorizacion existe principalmente en metodos de `User`, roles `BusinessRole`/`BackofficeRole`, abilities Sanctum y middleware.
- Middleware relevantes: `current.business`, `sync.request`, `backoffice.access`, `backoffice.super-admin`, locale, Inertia y appearance.

## Flujo actual de inventario

### Fuente de verdad

El sistema ya diferencia entre:

- Log operacional: ventas, compras, movimientos, ajustes, mermas, desgloses y seeds de producto.
- Proyeccion materializada: `stock_projections`.
- Snapshot legacy: `products.stock_by_warehouse`.

La fuente de verdad operativa debe tratarse como el log de transacciones y movimientos. `stock_projections` es la vista materializada usada para consultas rapidas y reconstruible con `inventory:rebuild`.

### Actualizacion de inventario

El flujo actual es:

1. Un dispositivo envia cambios a `SyncPushController`.
2. El evento se persiste en `sync_received_events`.
3. `SyncTransactionApplier` o `SyncEntityApplier` materializa el cambio.
4. Si el cambio afecta stock, `InventoryProjector` aplica deltas en `stock_projections`.
5. `InventoryProjector` sincroniza `products.stock_by_warehouse` para compatibilidad.

Casos actuales:

- Venta con estado `completed`, `credit` o `pending`: descuenta stock.
- Venta devuelta o cancelada despues de descontar: restaura stock.
- Compra `completed`: suma stock.
- Compra devuelta/cancelada despues de completar: descuenta lo que habia sumado.
- Movimiento: descuenta del almacen origen y suma al destino.
- Ajuste: aplica `change_quantity`.
- Merma: descuenta stock.
- Desglose: descuenta producto origen y suma producto destino.
- Producto con `_stockSeed: true`: reemplaza seed de almacenes incluidos.

### Sincronizacion

La sincronizacion offline esta basada en eventos idempotentes por `event_id`, `business_id`, dispositivo y cursor. El servidor materializa entidades para consultas de backoffice y pull. Algunos cambios no materializados pueden quedar como `pending_dispatch`, pero las entidades clave de operacion ya estan materializadas.

### Ventas

Una venta se registra desde el cliente offline como evento `sales`. El payload incluye lineas, total, estado, contacto, almacen, POS, actor y opcionalmente `inventory_consumption`. La materializacion crea o actualiza `sales` y reemplaza `sale_lines`; luego aplica la transicion de stock segun estado.

### Devoluciones

El Backoffice puede marcar una venta completada como `returned` agregando un evento de servidor via `CurrentBusinessSyncStore`. Cuando ese evento sea procesado/materializado por el flujo de Sync, el stock se restaura si antes estaba reduciendo.

### Compras

Una compra se registra como evento `purchases`; al pasar a `completed`, suma stock. El Backoffice permite completar, cancelar o eliminar compras pendientes escribiendo eventos de servidor.

### Puntos de extension

- Crear `InventoryAvailabilityService` como fachada de lectura de disponibilidad sobre `StockProjection` y futuras reservas.
- Mantener `InventoryProjector` como servicio de escritura/proyeccion de stock fisico.
- Migrar consultas de Backoffice que leen payloads de `SyncReceivedEvent` hacia modelos materializados para que Marketplace no dependa de payloads.
- Agregar reservas sin tocar el flujo de inventario fisico: disponibilidad = `stock_projections.qty - reservas_activas`.
- Separar comandos de reserva/confirmacion/cancelacion en servicios transaccionales.

## Flujo actual de pedidos y reutilizacion para Marketplace

Actualmente no existe un concepto de pedido Marketplace. La entidad mas cercana es `Sale`, que representa una venta por negocio/almacen/POS, no una orden maestra multi-vendedor.

Partes reutilizables:

- `Product`, `Warehouse`, `Business`, `StockProjection` como base de catalogo y disponibilidad.
- `InventoryProjector` para mutaciones de stock fisico.
- `Sale`/`SaleLine` como posible resultado operativo por vendedor cuando una orden Marketplace se confirma.
- Auth/Sanctum y versionado API para separar consumidores de app de gestion.
- Materializacion e indices por negocio.

Partes que no deben reutilizarse directamente:

- `SyncReceivedEvent` como fuente de catalogo publico o pedidos. Es un log de sync, no un contrato Marketplace.
- `products.stock_by_warehouse` como disponibilidad. Debe quedar como compatibilidad legacy.
- `Sale` como orden maestra. Una venta pertenece a un negocio; el Marketplace necesita una orden que agrupe varios vendedores.

## Riesgos identificados

### Concurrencia

- `InventoryProjector` bloquea filas existentes de `stock_projections`, pero el patron `lockForUpdate()` + create puede tener carreras si dos transacciones crean la misma proyeccion inexistente al mismo tiempo. La restriccion unique evita duplicados, pero hace falta capturar/reintentar errores de unique en flujos nuevos de alta concurrencia.
- Sin reservas, dos cotizaciones simultaneas podrian ver el mismo stock disponible.
- Confirmar Marketplace y ventas offline puede competir por el mismo stock. El servicio de disponibilidad debe usar transacciones y bloqueo sobre proyeccion/reservas.

### Duplicacion

- El Backoffice consulta ventas/compras desde payloads y tambien existen tablas materializadas. Marketplace debe apoyarse en tablas materializadas, no duplicar logica de parseo de payloads.
- El snapshot `Product.stock_by_warehouse` duplica `StockProjection`; debe seguir como compatibilidad, no como fuente nueva.

### Rendimiento

- El catalogo publico necesitara paginacion, busqueda e indices por publicacion, negocio, precio y disponibilidad.
- Consultar disponibilidad producto por producto provocaria N+1. El servicio debe soportar consultas batch.
- Para busqueda avanzada futura, conviene encapsular el repositorio de catalogo para poder migrar a scout/search sin tocar controllers.

### Dependencias circulares

- `Marketplace` no debe depender de Backoffice ni de Sync.
- `Inventory` puede depender de modelos compartidos y reservas, pero no de Marketplace.
- `Sales` puede exponer acciones reutilizables para registrar ventas operativas; Marketplace puede depender de esas acciones al confirmar, pero `Sales` no debe conocer Marketplace.

## Propuesta de arquitectura modular

No se recomienda mover codigo existente masivamente. La evolucion debe ser incremental, dentro de `app/Modules`, coexistiendo con `app/Models`, `app/Support` y controladores actuales hasta que las responsabilidades se estabilicen.

Estructura propuesta:

```text
app/
  Modules/
    Core/
    Catalog/
    Inventory/
    Sales/
    Marketplace/
    Delivery/
    Payments/
    Notifications/
    Shared/
```

### Core

Responsabilidades:

- Identidad, negocios, usuarios, roles, tokens y tenancy por negocio.
- Contratos transversales de autorizacion.
- Resolucion de negocio actual.

Codigo actual reutilizable:

- `User`, `Business`, `PersonalAccessToken`, roles, middleware `current.business`.

### Catalog

Responsabilidades:

- Productos, categorias, unidades, lotes y publicaciones.
- Reglas de visibilidad de producto.
- Transformacion de productos de gestion a catalogo publico.

Codigo actual reutilizable:

- `Product`, `Category`, `UnitOfMeasure`, `ProductBatch`.

### Inventory

Responsabilidades:

- Stock fisico.
- Disponibilidad centralizada.
- Reservas temporales.
- Rebuild y proyecciones.

Codigo actual reutilizable:

- `StockProjection`, `StockMovement`, `StockAdjustment`, `ProductLoss`, `ProductBreakdown`, `InventoryProjector`.

Nuevos servicios:

- `InventoryAvailabilityService`.
- `InventoryReservationService`.
- `ExpireInventoryReservationsAction`.

### Sales

Responsabilidades:

- Venta operativa por negocio.
- Lineas de venta.
- Transiciones de estado.
- Integracion con stock fisico al confirmar/cancelar/devolver.

Codigo actual reutilizable:

- `Sale`, `SaleLine`, `SyncTransactionApplier` como referencia de reglas actuales.

Nuevas acciones sugeridas:

- `CreateSaleFromMarketplaceSellerOrderAction`.
- `MarkSaleReturnedAction`.
- `CancelSaleAction`.

### Marketplace

Responsabilidades:

- Publicacion de productos.
- Catalogo publico.
- Busqueda.
- Cotizaciones.
- Reservas de inventario.
- Orden maestra.
- Ordenes por vendedor.
- Seguimiento de pedido.

No debe:

- Administrar productos directamente.
- Modificar stock fisico directamente.
- Leer tablas de inventario fuera de `InventoryAvailabilityService`.

### Delivery

Responsabilidades futuras:

- Direcciones, zonas, estados de entrega, proveedores logisticos.

Primera fase:

- Solo placeholders de dominio, sin integracion logistica.

### Payments

Responsabilidades futuras:

- Intenciones de pago, confirmaciones, reembolsos, conciliacion.

Primera fase:

- No implementar pagos. Solo dejar estados de orden compatibles con pago pendiente/confirmado.

### Notifications

Responsabilidades futuras:

- Eventos de orden, email, push, SMS, WhatsApp.

Primera fase:

- No implementar canales. Solo definir eventos de dominio si se introducen mas adelante.

### Shared

Responsabilidades:

- DTOs base, value objects, excepciones de dominio, helpers comunes.
- Contratos compartidos sin dependencia a Laravel HTTP.

## Servicio central de disponibilidad

### Contrato recomendado

```php
interface InventoryAvailabilityService
{
    public function availableFor(
        int $businessId,
        string $productExternalId,
        ?string $warehouseExternalId = null
    ): float;

    /**
     * @param array<int, array{business_id:int, product_external_id:string, warehouse_external_id?:string|null}> $items
     * @return array<string, float>
     */
    public function availableMany(array $items): array;

    public function assertAvailable(
        int $businessId,
        string $productExternalId,
        float $quantity,
        ?string $warehouseExternalId = null
    ): void;
}
```

### Regla de calculo

Disponibilidad = stock fisico proyectado - reservas activas no expiradas.

Stock fisico:

- Fuente: `stock_projections`.
- Agregable por producto en todos los almacenes o filtrado por almacen.

Stock reservado:

- Fuente futura: `inventory_reservations` y `inventory_reservation_lines`.
- Solo reservas con estado `active` y `expires_at > now()`.

### Reglas de acceso

- Todo modulo debe consultar disponibilidad por el servicio.
- `Marketplace` no debe leer `stock_projections` ni tablas de reservas directamente.
- Backoffice puede seguir leyendo `StockProjection` durante migracion, pero las nuevas funcionalidades deben usar el servicio.

## Sistema de reservas

### Entidades sugeridas

`InventoryReservation`:

- `id`
- `business_id`
- `owner_type`: por ejemplo `marketplace_quote`, `master_order`, `seller_order`.
- `owner_id`
- `status`: `active`, `confirmed`, `released`, `expired`, `cancelled`
- `expires_at`
- `confirmed_at`, `released_at`, `expired_at`, `cancelled_at`
- timestamps

`InventoryReservationLine`:

- `id`
- `inventory_reservation_id`
- `business_id`
- `product_external_id`
- `warehouse_external_id`
- `quantity`
- timestamps

Indices:

- `business_id`, `product_external_id`, `warehouse_external_id`, `status`, `expires_at`.
- unique opcional por `owner_type`, `owner_id`, `business_id`, `product_external_id`, `warehouse_external_id` para idempotencia.

### Servicios

- `InventoryReservationService::reserve()`: crea reserva transaccional; bloquea filas relevantes de `stock_projections` y reservas activas; valida disponibilidad.
- `InventoryReservationService::confirm()`: marca reserva confirmada y delega la creacion de venta/descuento final al flujo de confirmacion.
- `InventoryReservationService::release()`: libera reserva activa.
- `InventoryReservationService::expire()`: expira reservas vencidas.

### Expiracion

Primera fase:

- Comando Artisan `inventory:expire-reservations`.
- Scheduler en `routes/console.php` o `bootstrap/app.php` segun convencion Laravel 12.

Fase posterior:

- Job en cola si el volumen requiere procesamiento asincrono.

## Motor Marketplace

### Contrato

```php
interface MarketplaceEngineInterface
{
    public function quote(MarketplaceQuoteRequest $request): MarketplaceQuoteResult;

    public function reserve(MarketplaceReservationRequest $request): MarketplaceReservationResult;

    public function confirm(MarketplaceConfirmationRequest $request): MasterOrder;

    public function cancel(string $masterOrderNumber, ?string $reason = null): void;

    public function recalculate(string $quoteId): MarketplaceQuoteResult;
}
```

### Implementacion inicial

`HeuristicMarketplaceEngine`:

- Agrupa lineas por negocio vendedor.
- Consulta disponibilidad batch.
- Selecciona productos publicados con stock disponible.
- Reserva por vendedor/producto/almacen.
- Crea orden maestra y ordenes por vendedor.

El motor no debe saber como se calcula el stock. Solo usa `InventoryAvailabilityService` y `InventoryReservationService`.

## Entidades principales del Marketplace

### MarketplaceProductPublication

Representa que un producto de gestion esta publicado al catalogo publico.

Campos sugeridos:

- `id`
- `business_id`
- `product_external_id`
- `status`: `draft`, `published`, `paused`, `archived`
- `public_title`, `public_description`
- `public_price`
- `currency`
- `images`
- `metadata`
- timestamps

### MarketplaceQuote

Cotizacion temporal antes de crear orden.

Campos sugeridos:

- `id`
- `quote_number`
- `consumer_id` nullable inicialmente si hay checkout invitado.
- `status`: `draft`, `quoted`, `reserved`, `expired`, `converted`, `cancelled`
- `subtotal`, `delivery_total`, `fees_total`, `grand_total`, `currency`
- `expires_at`
- `payload_snapshot`
- timestamps

### MarketplaceQuoteLine

- `id`
- `marketplace_quote_id`
- `business_id`
- `product_external_id`
- `warehouse_external_id` nullable hasta reserva.
- `title_snapshot`
- `unit_price`
- `quantity`
- `subtotal`

### MasterOrder

Orden visible para el consumidor. Agrupa multiples vendedores.

Campos sugeridos:

- `id`
- `order_number`
- `consumer_id`
- `status`: `pending_payment`, `confirmed`, `partially_confirmed`, `in_fulfillment`, `completed`, `cancelled`, `refunded`
- `recipient_snapshot`
- `delivery_address_snapshot`
- `subtotal`, `delivery_total`, `fees_total`, `grand_total`, `currency`
- timestamps

### SellerOrder

Orden que ve cada vendedor. Pertenece a una orden maestra pero no expone el pedido completo.

Campos sugeridos:

- `id`
- `master_order_id`
- `business_id`
- `seller_order_number`
- `status`: `reserved`, `accepted`, `preparing`, `ready`, `dispatched`, `delivered`, `cancelled`
- `sale_id` nullable, para enlazar con la venta operativa cuando se materialice.
- `reservation_id` nullable.
- `subtotal`, `currency`
- timestamps

### SellerOrderLine

- `id`
- `seller_order_id`
- `product_external_id`
- `warehouse_external_id`
- `title_snapshot`
- `unit_price`
- `quantity`
- `subtotal`

### MarketplaceOrderStatusHistory

Historial de cambios de estado para consumidor, soporte y vendedor.

## APIs propuestas

### API de Gestion

Mantener `api/v1` para app actual:

- Auth, sync, cash register.
- Futuras rutas internas de vendedor Marketplace solo si el vendedor necesita operar ordenes desde la app de gestion.

### API Marketplace

Crear grupo separado:

```text
/api/marketplace/v1
```

Rutas iniciales:

- `GET /catalog/products`
- `GET /catalog/products/{publication}`
- `POST /quotes`
- `POST /quotes/{quote}/reserve`
- `POST /orders`
- `GET /orders/{orderNumber}`
- `POST /orders/{orderNumber}/cancel`

Controllers del Marketplace deben ser delgados y delegar en acciones/servicios.

## Plan de migracion sin romper funcionalidades

### Fase 0 - Alineacion y pruebas de inventario

- Mantener el codigo actual.
- Agregar pruebas de unidad/feature para `InventoryProjector` y transiciones clave de `SyncTransactionApplier`.
- Documentar invariantes de stock.
- Identificar endpoints que aun leen payloads de `SyncReceivedEvent`.

Estado: ejecutada el 2026-08-06.

Cobertura agregada o verificada en `tests/Feature/Api/V1/Sync/StockProjectionTest.php`:

- Seed explicito de producto con `_stockSeed` reemplaza la proyeccion por almacen.
- Upsert de producto sin `_stockSeed` no modifica stock.
- Venta `completed` descuenta stock.
- Venta con `inventoryConsumption` descuenta componentes/receta en lugar del producto vendido.
- Venta devuelta restaura stock.
- Compra `completed` suma stock.
- Compra `canceled` despues de estar completada revierte el stock sumado.
- Movimiento de stock descuenta origen y suma destino.
- Ajuste aplica `changeQuantity`.
- Merma descuenta stock.
- Desglose descuenta producto origen y suma producto destino.
- Borrado de movimiento, ajuste, merma y desglose revierte sus deltas.
- Replay del mismo evento de venta no duplica descuento.
- `products.stock_by_warehouse` sigue espejando `stock_projections` por compatibilidad legacy.

Invariantes documentados:

- `stock_projections` es la vista materializada de stock fisico reconstruible.
- Las reservas futuras no deben modificar stock fisico; solo deben reducir disponibilidad.
- Las ventas reducen stock solo en estados `completed`, `credit` y `pending`.
- Las ventas que pasan a `returned` o `canceled` restauran el stock si antes estaban reduciendo.
- Las compras suman stock solo al entrar en `completed`.
- Las compras que pasan de `completed` a `returned` o `canceled` revierten lo sumado.
- Movimientos, ajustes, mermas y desgloses deben ser reversibles cuando llega una operacion `delete`.
- Para ventas con recetas, el efecto de stock debe preferir `inventoryConsumption`; si no existe, usa las lineas de venta.

Lectores actuales de `SyncReceivedEvent` o `CurrentBusinessSyncStore` que deben migrarse antes de que Marketplace dependa de esos datos:

- `app/Http/Controllers/Backoffice/SaleController.php`
- `app/Http/Controllers/Backoffice/PurchaseController.php`
- `app/Http/Controllers/Backoffice/ExpenseController.php`
- `app/Http/Controllers/Backoffice/CurrentBusinessEmployeeController.php`
- `app/Http/Controllers/Backoffice/CurrentBusinessMemberController.php`
- `app/Http/Controllers/Backoffice/BusinessPreparationController.php`
- `app/Http/Controllers/Backoffice/DashboardController.php`
- `app/Support/Licensing/BusinessLicensePricingResolver.php`
- `app/Support/Backoffice/CurrentBusinessSyncStore.php`

Nota: `SyncPushController`, `SyncEntityApplier`, `SyncTransactionApplier`, `SyncEventReprocessor`, `ReprocessFailedEventsController` y `PruneSyncReceivedEventsCommand` usan `SyncReceivedEvent` como parte del flujo normal de sincronizacion/log, no como lector de catalogo publico o Marketplace.

### Fase 1 - Disponibilidad centralizada

- Crear `app/Modules/Inventory` sin mover codigo existente.
- Introducir `InventoryAvailabilityService`.
- Implementar lectura desde `stock_projections`.
- Actualizar nuevas consultas para usar el servicio.
- Agregar pruebas batch y por almacen.

Estado: ejecutada el 2026-08-06.

Implementacion:

- Contrato `App\Modules\Inventory\Contracts\InventoryAvailabilityService`.
- Implementacion `App\Modules\Inventory\Services\StockProjectionInventoryAvailabilityService`.
- Excepcion de dominio `App\Modules\Inventory\Exceptions\InsufficientInventoryAvailable`.
- Binding en `App\Providers\AppServiceProvider`.

Comportamiento cubierto:

- `availableFor()` devuelve disponibilidad agregada del producto en todos los almacenes.
- `availableFor(..., warehouse)` devuelve disponibilidad en un almacen especifico.
- `availableMany()` resuelve varios productos/almacenes en batch preservando claves del llamador.
- `assertAvailable()` permite cantidades disponibles y lanza `InsufficientInventoryAvailable` cuando no alcanzan.
- La disponibilidad de Fase 1 lee solo `stock_projections`; reservas se incorporan en Fase 2.

Pruebas:

- `tests/Feature/Inventory/InventoryAvailabilityServiceTest.php`

### Fase 2 - Reservas

- Crear migraciones/modelos de reservas.
- Implementar `InventoryReservationService`.
- Agregar expiracion por comando y scheduler.
- Probar concurrencia basica con transacciones y bloqueo.

Estado: ejecutada el 2026-08-06.

Implementacion:

- Migracion `database/migrations/2026_08_06_202752_create_inventory_reservations_table.php`.
- Modelos `App\Models\InventoryReservation` y `App\Models\InventoryReservationLine`.
- Factories de reservas y lineas para pruebas.
- Contrato `App\Modules\Inventory\Contracts\InventoryReservationService`.
- Implementacion `App\Modules\Inventory\Services\EloquentInventoryReservationService`.
- Binding en `App\Providers\AppServiceProvider`.
- Comando `inventory:expire-reservations`.
- Scheduler cada cinco minutos en `routes/console.php`.

Comportamiento cubierto:

- `reserve()` crea reserva activa y lineas transaccionalmente.
- Reservar no modifica `stock_projections`; solo reduce disponibilidad.
- `InventoryAvailabilityService` descuenta reservas `active` con `expires_at > now()`.
- Reservas `released`, `confirmed`, `cancelled` y vencidas no reducen disponibilidad.
- Un segundo intento para el mismo `owner_type` + `owner_id` + negocio devuelve la reserva activa existente.
- Sobre-reservar lanza `InsufficientInventoryAvailable` y no persiste la nueva reserva.
- `confirm()`, `release()`, `cancel()` y `expire()` hacen transiciones desde `active`.
- `expirePastDue()` y el comando `inventory:expire-reservations` expiran reservas vencidas.

Pruebas:

- `tests/Feature/Inventory/InventoryReservationServiceTest.php`
- `tests/Feature/Inventory/InventoryAvailabilityServiceTest.php`

### Fase 3 - Catalogo publico

- Crear `app/Modules/Marketplace`.
- Crear publicaciones de producto.
- Exponer catalogo publico paginado.
- Usar disponibilidad centralizada para filtros de stock.

Estado: ejecutada el 2026-08-06.

Implementacion:

- Migracion `database/migrations/2026_08_06_203743_create_marketplace_product_publications_table.php`.
- Modelo `App\Models\MarketplaceProductPublication`.
- Factory de publicaciones Marketplace.
- Request `App\Http\Requests\Api\Marketplace\V1\CatalogProductIndexRequest`.
- Resource `App\Http\Resources\Api\Marketplace\V1\MarketplaceProductPublicationResource`.
- Controller `App\Http\Controllers\Api\Marketplace\V1\CatalogProductController`.
- Servicio `App\Modules\Marketplace\Catalog\MarketplaceCatalogService`.
- Rutas publicas:
  - `GET /api/marketplace/v1/catalog/products`
  - `GET /api/marketplace/v1/catalog/products/{publication}`

Comportamiento cubierto:

- Solo publicaciones `published` son visibles en el catalogo publico.
- Cada publicacion apunta a un `warehouse_external_id`; para el flujo actual debe ser el almacén eCommerce sincronizado desde la app.
- El listado es paginado y ordenado por titulo publico.
- Filtros iniciales: busqueda `q`, `business_id`, `in_stock`, `per_page`.
- La busqueda encuentra por campos publicos y por campos materializados del producto base.
- La disponibilidad se calcula con `InventoryAvailabilityService` usando `business_id`, `product_external_id` y `warehouse_external_id`; Marketplace no lee `stock_projections` directamente.
- El filtro `in_stock` respeta reservas activas vigentes porque depende de disponibilidad centralizada.
- El detalle devuelve 404 para publicaciones no publicadas.

Conexion con `Almacén eCommerce`:

- La app debe crear/sincronizar el almacén eCommerce como `warehouses.external_id`.
- Las publicaciones Marketplace deben guardar ese `external_id` en `warehouse_external_id`.
- El dueño reserva inventario para Marketplace moviendo stock hacia ese almacén; esos movimientos actualizan `stock_projections`.
- El catalogo publico muestra disponibilidad solo del almacén eCommerce, no del stock total del producto.
- Las reservas de fases siguientes deben usar el mismo `warehouse_external_id` en sus lineas.

Pruebas:

- `tests/Feature/Api/Marketplace/V1/CatalogProductApiTest.php`

### Fase 4 - Cotizaciones

- Crear `MarketplaceEngineInterface` e implementacion heuristica.
- Crear DTOs de quote/reserve/confirm.
- Persistir quotes y quote lines.
- Reservar inventario de forma temporal.

Estado: ejecutada el 2026-08-07.

Implementacion:

- Migracion `database/migrations/2026_08_06_211957_create_marketplace_quotes_table.php`.
- Modelos `App\Models\MarketplaceQuote` y `App\Models\MarketplaceQuoteLine`.
- Factories de quotes y quote lines.
- DTOs:
  - `App\Modules\Marketplace\DTOs\MarketplaceQuoteRequest`
  - `App\Modules\Marketplace\DTOs\MarketplaceQuoteResult`
  - `App\Modules\Marketplace\DTOs\MarketplaceReservationRequest`
  - `App\Modules\Marketplace\DTOs\MarketplaceReservationResult`
- Contrato `App\Modules\Marketplace\Contracts\MarketplaceEngineInterface`.
- Implementacion `App\Modules\Marketplace\Engine\HeuristicMarketplaceEngine`.
- Binding en `App\Providers\AppServiceProvider`.
- Requests:
  - `App\Http\Requests\Api\Marketplace\V1\StoreMarketplaceQuoteRequest`
  - `App\Http\Requests\Api\Marketplace\V1\ReserveMarketplaceQuoteRequest`
- Resource `App\Http\Resources\Api\Marketplace\V1\MarketplaceQuoteResource`.
- Controller `App\Http\Controllers\Api\Marketplace\V1\MarketplaceQuoteController`.
- Rutas publicas:
  - `POST /api/marketplace/v1/quotes`
  - `POST /api/marketplace/v1/quotes/{quote}/reserve`

Comportamiento cubierto:

- `quote()` valida publicaciones `published`, disponibilidad por `warehouse_external_id` y moneda unica.
- La cotizacion persiste `MarketplaceQuote` y `MarketplaceQuoteLine` con snapshots de titulo, precio, producto, negocio y almacén eCommerce.
- La cotizacion no toca stock fisico ni crea reservas.
- `reserve()` agrupa lineas por negocio y crea reservas temporales con `owner_type = marketplace_quote` y `owner_id = quote_number`.
- Las reservas usan el mismo `warehouse_external_id` de la publicacion, por lo que solo bloquean inventario del almacén eCommerce.
- Cotizaciones expiradas o sin inventario suficiente devuelven error 422.
- Fase 4 no crea orden maestra ni ordenes por vendedor; eso queda para Fase 5.

Pruebas:

- `tests/Feature/Api/Marketplace/V1/MarketplaceQuoteApiTest.php`

### Fase 5 - Orden maestra y ordenes por vendedor

- Crear `MasterOrder`, `SellerOrder`, lineas e historial de estado.
- Confirmar reservas.
- Generar seller orders por negocio.
- Aislar visibilidad: vendedor solo accede a su `SellerOrder`.

Estado: ejecutada el 2026-08-07.

Implementacion:

- Migracion `database/migrations/2026_08_07_133130_create_master_orders_table.php`.
- Modelos:
  - `App\Models\MasterOrder`
  - `App\Models\SellerOrder`
  - `App\Models\SellerOrderLine`
  - `App\Models\MarketplaceOrderStatusHistory`
- DTOs:
  - `App\Modules\Marketplace\DTOs\MarketplaceConfirmationRequest`
  - `App\Modules\Marketplace\DTOs\MarketplaceConfirmationResult`
- `MarketplaceEngineInterface::confirm()`.
- `HeuristicMarketplaceEngine::confirm()`.
- Request `App\Http\Requests\Api\Marketplace\V1\ConfirmMarketplaceQuoteRequest`.
- Resource `App\Http\Resources\Api\Marketplace\V1\MasterOrderResource`.
- Controller `App\Http\Controllers\Api\Marketplace\V1\MarketplaceOrderController`.
- Rutas publicas:
  - `POST /api/marketplace/v1/quotes/{quote}/confirm`
  - `GET /api/marketplace/v1/orders/{orderNumber}`

Comportamiento cubierto:

- Una cotizacion `reserved` y vigente se convierte en `MasterOrder`.
- Se crea una `SellerOrder` por cada negocio vendedor incluido en la cotizacion.
- Cada `SellerOrder` conserva `reservation_id`, `business_id`, subtotal, moneda y lineas propias.
- Las lineas de vendedor conservan `product_external_id`, `warehouse_external_id`, titulo, precio y cantidad.
- Se registra historial de estado para la orden maestra y las ordenes de vendedor.
- Confirmar dos veces la misma cotizacion es idempotente y devuelve la misma orden.
- El endpoint publico permite consultar por `order_number`.
- Fase 5 no crea `Sale` ni descuenta stock fisico; eso queda para Fase 6.

Pruebas:

- `tests/Feature/Api/Marketplace/V1/MarketplaceOrderApiTest.php`

### Fase 6 - Integracion con venta operativa

- Crear accion en `Sales` para generar `Sale` desde `SellerOrder`.
- Definir si la venta se crea al confirmar pago, al aceptar vendedor o al despachar.
- Evitar doble descuento: la reserva reduce disponibilidad, pero el stock fisico solo debe moverse una vez por la venta confirmada.

Estado: ejecutada el 2026-08-07.

Decision aplicada:

- La venta operativa se crea cuando el vendedor acepta su `SellerOrder`.
- La venta se crea con estado `pending`, `payment_method = marketplace` y referencia igual a `seller_order_number`.
- La accion confirma la reserva despues de descontar stock fisico, por lo que la reserva deja de reducir disponibilidad.
- La disponibilidad final queda representada por el stock fisico ya descontado, evitando doble descuento.

Implementacion:

- Accion `App\Modules\Sales\Actions\CreateSaleFromMarketplaceSellerOrderAction`.
- Controller `App\Http\Controllers\Api\Marketplace\V1\MarketplaceSellerOrderController`.
- Ruta publica:
  - `POST /api/marketplace/v1/seller-orders/{sellerOrder}/accept`

Comportamiento cubierto:

- Aceptar una `SellerOrder` reservada crea una `Sale` y sus `SaleLine`.
- La venta usa el mismo `warehouse_external_id` de las lineas de vendedor, conectado al `Almacén eCommerce`.
- El stock fisico en `stock_projections` se descuenta una sola vez mediante `InventoryProjector`.
- La reserva pasa de `active` a `confirmed`.
- La `SellerOrder` pasa de `reserved` a `accepted` y guarda `sale_id`.
- La `MasterOrder` pasa a `in_fulfillment`.
- La aceptacion es idempotente: repetir el endpoint devuelve la orden sin crear otra venta ni descontar stock otra vez.
- Reservas vencidas o stock fisico insuficiente devuelven error 422.

Restriccion actual:

- Una `SellerOrder` solo se acepta si todas sus lineas pertenecen al mismo `warehouse_external_id`, porque `Sale` almacena un solo almacen en cabecera. Si en el futuro una orden de vendedor mezcla almacenes, debe dividirse en ventas por almacen.

Pruebas:

- `tests/Feature/Api/Marketplace/V1/MarketplaceSaleIntegrationTest.php`

### Fase 7 - Endurecimiento

- Indices de busqueda/catalogo.
- Observabilidad de reservas expiradas y conversion.
- Eventos de dominio para notificaciones futuras.
- Preparacion para pagos y delivery sin implementarlos aun.

Estado: ejecutada el 2026-08-07.

Implementacion:

- Migracion `database/migrations/2026_08_07_134642_add_marketplace_hardening_fields.php`.
- Campos de preparacion en `master_orders`:
  - `payment_status`
  - `delivery_status`
  - `payment_snapshot`
  - `delivery_snapshot`
- Indices adicionales para:
  - busqueda/listado de publicaciones por estado, titulo, negocio y precio.
  - cotizaciones por estado y fechas.
  - reservas por estado y fecha de actualizacion.
  - ordenes maestras por estado de pago/delivery.
  - ordenes de vendedor por negocio, estado y fecha de actualizacion.
- Eventos de dominio:
  - `App\Events\MarketplaceQuoteReserved`
  - `App\Events\MarketplaceOrderConfirmed`
  - `App\Events\MarketplaceSellerOrderAccepted`
  - `App\Events\InventoryReservationExpired`
- Servicio `App\Modules\Marketplace\Operations\MarketplaceOperationalMetricsService`.
- Comando `marketplace:health`, con salida tabular o `--json`.

Comportamiento cubierto:

- Confirmar una cotizacion puede guardar snapshots opcionales de pago y delivery sin ejecutar pagos ni envios reales.
- Los endpoints de orden devuelven `payment_status`, `delivery_status`, `payment` y `delivery`.
- Las transiciones relevantes emiten eventos de dominio solo cuando ocurre una conversion real, no en respuestas idempotentes.
- La expiracion de reservas emite evento por cada reserva activa vencida que cambia a `expired`.
- Las metricas operativas exponen reservas activas/vencidas, cotizaciones por estado, tasa de conversion, ordenes por estado y seller orders inconsistentes.

Pruebas:

- `tests/Feature/Api/Marketplace/V1/MarketplaceHardeningTest.php`

## Servicios reutilizables identificados

- `InventoryProjector`: mantener como motor de proyeccion de stock fisico.
- `SyncTransactionApplier`: reutilizar reglas actuales de estados de venta/compra como referencia, pero extraer acciones de dominio antes de que Marketplace cree ventas.
- `CurrentBusinessSyncStore`: seguir para Backoffice mientras se migra, pero no usar como dependencia Marketplace.
- `BusinessPolicies`: reutilizable para politicas por negocio.
- Auth/Sanctum, roles y middleware de negocio actual.
- Exportadores no son parte Marketplace, pero pueden inspirar reportes de seller orders.

## Decisiones recomendadas

1. `StockProjection` queda como base de stock fisico.
2. `InventoryAvailabilityService` es obligatorio para toda disponibilidad nueva.
3. Reservas no modifican stock fisico; solo reducen disponibilidad.
4. Marketplace crea `MasterOrder` y `SellerOrder`, no escribe directamente `Sale` hasta una accion explicita de confirmacion operativa.
5. `SyncReceivedEvent` permanece como log de sincronizacion, no como API interna de Marketplace.
6. La modularizacion empieza agregando codigo nuevo en `app/Modules`; el codigo existente se migra solo cuando haya pruebas y bajo riesgo.

## Primer backlog tecnico recomendado

- Crear pruebas de invariantes de stock para venta, devolucion, compra, movimiento, ajuste, merma y desglose.
- Crear `InventoryAvailabilityService` con lectura batch desde `StockProjection`.
- Crear migraciones de reservas e implementar reserva/liberacion/expiracion.
- Crear contrato `MarketplaceEngineInterface` y DTOs.
- Crear modelos de publicaciones y catalogo publico.
- Crear modelos de orden maestra y orden por vendedor.
- Crear acciones de confirmacion que unan orden, reserva y venta operativa.

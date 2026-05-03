<?php

namespace App\Http\Controllers\Api\V1\CashRegister;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashRegister\CloseSessionRequest;
use App\Http\Requests\Api\V1\CashRegister\OpenSessionRequest;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\PersonalAccessToken;
use App\Models\PointOfSale;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashRegisterSessionController extends Controller
{
    /**
     * Return the currently open session for the given POS, or null.
     */
    public function active(Request $request, string $posExternalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $this->ensurePosBelongsToBusiness($business, $posExternalId);

        $session = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->forPos($posExternalId)
            ->open()
            ->first();

        return response()->json([
            'session' => $session ? $this->toPayload($session) : null,
        ]);
    }

    /**
     * Open a session for the given POS, or join the existing open one (idempotent).
     */
    public function open(OpenSessionRequest $request, string $posExternalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $this->ensurePosBelongsToBusiness($business, $posExternalId);

        $warehouseExternalId = $request->input('warehouse_external_id')
            ?? $this->resolveWarehouseFromPos($business, $posExternalId);

        $openedAt = $this->parseDate($request->input('opened_at')) ?? now();
        $openedBy = $this->normalizeActor($request->input('opened_by'));
        $deviceId = $this->resolveDeviceId($request);

        return DB::transaction(function () use ($business, $posExternalId, $request, $warehouseExternalId, $openedAt, $openedBy, $deviceId): JsonResponse {
            $existingOpen = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->forPos($posExternalId)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($existingOpen) {
                $joined = $existingOpen->external_id !== $request->string('external_id')->toString();

                // Si el master lease expiró y este dispositivo se está
                // sumando a la sesión, déjalo tomarlo como master en el
                // mismo viaje. Evita un round-trip extra al cliente.
                if ($deviceId && ! $existingOpen->masterLeaseIsActive()) {
                    $existingOpen->forceFill([
                        'master_device_id' => $deviceId,
                        'master_lease_expires_at' => now()->addSeconds(CashRegisterSession::MASTER_LEASE_TTL_SECONDS),
                        'source_updated_at' => now(),
                    ])->save();
                }

                return response()->json([
                    'session' => $this->toPayload($existingOpen),
                    'joined' => $joined,
                ], 200);
            }

            try {
                $session = CashRegisterSession::query()->create([
                    'business_id' => $business->id,
                    'external_id' => $request->string('external_id')->toString(),
                    'pos_external_id' => $posExternalId,
                    'warehouse_external_id' => $warehouseExternalId,
                    'master_device_id' => $deviceId,
                    'master_lease_expires_at' => $deviceId
                        ? now()->addSeconds(CashRegisterSession::MASTER_LEASE_TTL_SECONDS)
                        : null,
                    'status' => 'open',
                    'opened_at' => $openedAt,
                    'opening_balance' => (float) $request->input('opening_balance', 0),
                    'opened_by' => $openedBy,
                    'initial_inventory_snapshot' => $request->input('initial_inventory_snapshot'),
                    'source_created_at' => $openedAt,
                    'source_updated_at' => $openedAt,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    $existingByExternalId = CashRegisterSession::query()
                        ->where('business_id', $business->id)
                        ->where('external_id', $request->string('external_id')->toString())
                        ->first();

                    if ($existingByExternalId) {
                        return response()->json([
                            'session' => $this->toPayload($existingByExternalId),
                            'joined' => false,
                        ], 200);
                    }

                    $existingByPos = CashRegisterSession::query()
                        ->where('business_id', $business->id)
                        ->forPos($posExternalId)
                        ->open()
                        ->first();

                    if ($existingByPos) {
                        return response()->json([
                            'session' => $this->toPayload($existingByPos),
                            'joined' => true,
                        ], 200);
                    }
                }

                throw $exception;
            }

            return response()->json([
                'session' => $this->toPayload($session),
                'joined' => false,
            ], 201);
        });
    }

    /**
     * Close an open session (idempotent on already-closed sessions: returns 409 with current state).
     */
    public function close(CloseSessionRequest $request, string $externalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $session = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('external_id', $externalId)
            ->firstOrFail();

        if (! $session->isOpen()) {
            return response()->json([
                'session' => $this->toPayload($session),
                'message' => 'La sesión ya estaba cerrada.',
                'code' => 'cash_register_session_already_closed',
            ], 409);
        }

        $closedAt = $this->parseDate($request->input('closed_at')) ?? now();
        $closedBy = $this->normalizeActor($request->input('closed_by'))
            ?? $this->actorFromToken($request);

        $session->fill([
            'status' => 'closed',
            'closed_at' => $closedAt,
            'closing_balance' => (float) $request->input('closing_balance', 0),
            'closed_by' => $closedBy,
            'final_inventory_snapshot' => $request->input('final_inventory_snapshot'),
            // El lease deja de tener sentido en una sesión cerrada.
            'master_device_id' => null,
            'master_lease_expires_at' => null,
            'source_updated_at' => $closedAt,
        ])->save();

        return response()->json([
            'session' => $this->toPayload($session),
        ]);
    }

    /**
     * Claim the master lease for this session. Allowed when:
     *  - the session has no current master, OR
     *  - the lease has expired, OR
     *  - this device already holds the lease (idempotent renewal).
     *
     * Reject with 409 if another device holds an active lease.
     */
    public function claimMaster(Request $request, string $externalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $deviceId = $this->resolveDeviceId($request);

        if (! $deviceId) {
            abort(422, 'No se pudo determinar el dispositivo solicitante.');
        }

        return DB::transaction(function () use ($business, $externalId, $deviceId): JsonResponse {
            /** @var CashRegisterSession|null $session */
            $session = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                abort(404, 'La sesión solicitada no existe.');
            }

            if (! $session->isOpen()) {
                return response()->json([
                    'session' => $this->toPayload($session),
                    'message' => 'La sesión está cerrada; el lease ya no aplica.',
                    'code' => 'cash_register_session_already_closed',
                ], 409);
            }

            $now = now();
            $leaseActive = $session->masterLeaseIsActive($now);
            $isCurrentHolder = $session->master_device_id === $deviceId;

            if ($leaseActive && ! $isCurrentHolder) {
                return response()->json([
                    'session' => $this->toPayload($session),
                    'message' => 'Otro dispositivo tiene el lease de master para esta sesión.',
                    'code' => 'cash_register_master_held',
                ], 409);
            }

            $session->forceFill([
                'master_device_id' => $deviceId,
                'master_lease_expires_at' => $now->copy()->addSeconds(CashRegisterSession::MASTER_LEASE_TTL_SECONDS),
                'source_updated_at' => $now,
            ])->save();

            return response()->json([
                'session' => $this->toPayload($session),
            ]);
        });
    }

    /**
     * Refresh the lease. Only the current holder may renew, and only while
     * the lease is still active. If it expired, the holder must claim again
     * (this prevents zombie holders racing with new claimants).
     */
    public function refreshMaster(Request $request, string $externalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $deviceId = $this->resolveDeviceId($request);

        if (! $deviceId) {
            abort(422, 'No se pudo determinar el dispositivo solicitante.');
        }

        return DB::transaction(function () use ($business, $externalId, $deviceId): JsonResponse {
            /** @var CashRegisterSession|null $session */
            $session = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                abort(404, 'La sesión solicitada no existe.');
            }

            if (! $session->isOpen()) {
                return response()->json([
                    'session' => $this->toPayload($session),
                    'message' => 'La sesión está cerrada; el lease ya no aplica.',
                    'code' => 'cash_register_session_already_closed',
                ], 409);
            }

            if (! $session->isMasteredBy($deviceId)) {
                return response()->json([
                    'session' => $this->toPayload($session),
                    'message' => 'Este dispositivo no tiene el lease activo. Debe reclamarlo.',
                    'code' => 'cash_register_master_lease_lost',
                ], 409);
            }

            $session->forceFill([
                'master_lease_expires_at' => now()->addSeconds(CashRegisterSession::MASTER_LEASE_TTL_SECONDS),
                'source_updated_at' => now(),
            ])->save();

            return response()->json([
                'session' => $this->toPayload($session),
            ]);
        });
    }

    /**
     * Release the master lease (e.g. on logout / app close). Idempotent: if
     * this device isn't the current holder, returns 200 with the session
     * unchanged.
     */
    public function releaseMaster(Request $request, string $externalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $deviceId = $this->resolveDeviceId($request);

        return DB::transaction(function () use ($business, $externalId, $deviceId): JsonResponse {
            /** @var CashRegisterSession|null $session */
            $session = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                abort(404, 'La sesión solicitada no existe.');
            }

            if ($session->master_device_id === $deviceId) {
                $session->forceFill([
                    'master_device_id' => null,
                    'master_lease_expires_at' => null,
                    'source_updated_at' => now(),
                ])->save();
            }

            return response()->json([
                'session' => $this->toPayload($session),
            ]);
        });
    }

    /**
     * Recalculate session totals from related sales (not persisted).
     */
    public function summary(Request $request, string $externalId): JsonResponse
    {
        $business = $this->resolveBusiness($request);
        $session = CashRegisterSession::query()
            ->where('business_id', $business->id)
            ->where('external_id', $externalId)
            ->firstOrFail();

        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->where('cash_register_session_id', $externalId)
            ->whereNull('deleted_at')
            ->get(['payment_method', 'status', 'total']);

        $countedStatuses = ['completed', 'credit', 'pending'];
        $relevantSales = $sales->filter(fn (Sale $sale): bool => in_array($sale->status, $countedStatuses, true));

        $totalsByMethod = $relevantSales
            ->groupBy(fn (Sale $sale): string => $sale->payment_method ?? 'unknown')
            ->map(fn ($group) => round((float) $group->sum('total'), 2));

        $cashTotal = (float) $totalsByMethod->get('cash', 0);
        $cardTotal = (float) $totalsByMethod->get('card', 0);
        $creditTotal = (float) $totalsByMethod->get('credit', 0);
        $salesTotal = (float) $relevantSales->sum('total');

        $expectedBalance = round((float) $session->opening_balance + $cashTotal, 2);
        $discrepancy = $session->closing_balance !== null
            ? round((float) $session->closing_balance - $expectedBalance, 2)
            : null;

        return response()->json([
            'session' => $this->toPayload($session),
            'summary' => [
                'sales_count' => $relevantSales->count(),
                'sales_total' => round($salesTotal, 2),
                'totals_by_method' => [
                    'cash' => $cashTotal,
                    'card' => $cardTotal,
                    'credit' => $creditTotal,
                ],
                'expected_balance' => $expectedBalance,
                'discrepancy' => $discrepancy,
            ],
        ]);
    }

    private function resolveBusiness(Request $request): Business
    {
        $business = $request->attributes->get('currentBusiness');
        abort_unless($business instanceof Business, 409, 'No existe un negocio actual activo para sincronizar.');

        return $business;
    }

    /**
     * Best-effort resolution of the device making the request. Tries (in order):
     *  - explicit `device_id` body field (sent by claim/refresh from the client)
     *  - `opened_by.deviceId` payload (used when opening a session)
     *  - `X-Device-Id` header (set by the sync layer)
     *  - the device id baked into the personal access token
     */
    private function resolveDeviceId(Request $request): ?string
    {
        $candidates = [
            $request->input('device_id'),
            data_get($request->input('opened_by'), 'deviceId'),
            data_get($request->input('closed_by'), 'deviceId'),
            $request->header('X-Device-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        /** @var PersonalAccessToken|null $token */
        $token = $request->user()?->currentAccessToken();
        $tokenDeviceId = $token?->device_uuid ?? null;
        if (is_string($tokenDeviceId) && trim($tokenDeviceId) !== '') {
            return trim($tokenDeviceId);
        }

        return null;
    }

    private function ensurePosBelongsToBusiness(Business $business, string $posExternalId): void
    {
        $exists = PointOfSale::query()
            ->where('business_id', $business->id)
            ->where('external_id', $posExternalId)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($exists, 404, 'El punto de venta no pertenece a este negocio.');
    }

    private function resolveWarehouseFromPos(Business $business, string $posExternalId): ?string
    {
        $pos = PointOfSale::query()
            ->where('business_id', $business->id)
            ->where('external_id', $posExternalId)
            ->first();

        return $pos?->warehouse_external_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeActor(mixed $actor): ?array
    {
        if (! is_array($actor)) {
            return null;
        }

        $normalized = [
            'id' => isset($actor['id']) ? (string) $actor['id'] : null,
            'role' => isset($actor['role']) ? (string) $actor['role'] : null,
            'name' => isset($actor['name']) ? (string) $actor['name'] : null,
            'deviceId' => isset($actor['deviceId']) ? (string) $actor['deviceId'] : null,
            'deviceName' => isset($actor['deviceName']) ? (string) $actor['deviceName'] : null,
        ];

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Build a closedBy actor from the auth token when the client doesn't send one.
     *
     * @return array<string, mixed>|null
     */
    private function actorFromToken(Request $request): ?array
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return null;
        }

        $role = $token->can('sync:owner') ? 'owner' : ($token->can('sync:seller') ? 'seller' : 'unknown');
        $name = $request->user()?->name ?? 'Usuario';

        return array_filter([
            'id' => $token->employee_external_id ?? (string) ($request->user()?->id ?? ''),
            'role' => $role,
            'name' => $name,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $code = $exception->errorInfo[1] ?? null;
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $code === 1062
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(CashRegisterSession $session): array
    {
        return [
            'external_id' => $session->external_id,
            'pos_external_id' => $session->pos_external_id,
            'warehouse_external_id' => $session->warehouse_external_id,
            'status' => $session->status,
            'master_device_id' => $session->master_device_id,
            'master_lease_expires_at' => $session->master_lease_expires_at?->toIso8601String(),
            'master_lease_ttl_seconds' => CashRegisterSession::MASTER_LEASE_TTL_SECONDS,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'opening_balance' => $session->opening_balance !== null ? (float) $session->opening_balance : 0.0,
            'closing_balance' => $session->closing_balance !== null ? (float) $session->closing_balance : null,
            'opened_by' => $session->opened_by,
            'closed_by' => $session->closed_by,
            'initial_inventory_snapshot' => $session->initial_inventory_snapshot,
            'final_inventory_snapshot' => $session->final_inventory_snapshot,
            'source_updated_at' => $session->source_updated_at?->toIso8601String(),
        ];
    }
}

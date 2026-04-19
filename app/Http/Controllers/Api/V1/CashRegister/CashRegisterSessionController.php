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

        return DB::transaction(function () use ($business, $posExternalId, $request, $warehouseExternalId, $openedAt, $openedBy): JsonResponse {
            $existingOpen = CashRegisterSession::query()
                ->where('business_id', $business->id)
                ->forPos($posExternalId)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($existingOpen) {
                $joined = $existingOpen->external_id !== $request->string('external_id')->toString();

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
            'source_updated_at' => $closedAt,
        ])->save();

        return response()->json([
            'session' => $this->toPayload($session),
        ]);
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

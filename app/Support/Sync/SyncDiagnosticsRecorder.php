<?php

namespace App\Support\Sync;

use App\Models\Business;
use App\Models\SyncDiagnostic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncDiagnosticsRecorder
{
    /**
     * @param  array<string, mixed>  $responseMeta
     * @param  array<string, mixed>|null  $error
     */
    public function record(
        Request $request,
        string $stage,
        string $status,
        int $httpStatus,
        array $responseMeta = [],
        ?array $error = null
    ): SyncDiagnostic {
        $requestId = (string) ($request->attributes->get('sync_request_id') ?: Str::uuid()->toString());
        $request->attributes->set('sync_request_id', $requestId);

        $business = $request->attributes->get('currentBusiness');
        $businessId = $business instanceof Business ? $business->id : null;
        $clientAppVersion = app(SyncCompatibility::class)->clientAppVersion($request);
        $clientSyncVersion = app(SyncCompatibility::class)->clientSyncVersion($request);
        $serverAppVersion = app(SyncCompatibility::class)->currentAppVersion();
        $serverSyncVersion = app(SyncCompatibility::class)->currentProtocolVersion();

        $diagnostic = SyncDiagnostic::query()->create([
            'request_id' => $requestId,
            'business_id' => $businessId,
            'user_id' => $request->user()?->id,
            'device_id' => $this->resolveDeviceId($request),
            'stage' => $stage,
            'route_name' => $request->route()?->getName(),
            'status' => $status,
            'http_status' => $httpStatus,
            'error_code' => $error['code'] ?? null,
            'error_message' => $error['message'] ?? null,
            'client_app_version' => $clientAppVersion,
            'client_sync_version' => $clientSyncVersion !== null ? (string) $clientSyncVersion : null,
            'server_app_version' => $serverAppVersion,
            'server_sync_version' => $serverSyncVersion,
            'request_meta' => $this->buildRequestMeta($request),
            'response_meta' => $responseMeta,
        ]);

        if ($status !== 'success') {
            Log::warning('Sync request diagnostic recorded.', [
                'request_id' => $requestId,
                'stage' => $stage,
                'status' => $status,
                'http_status' => $httpStatus,
                'error_code' => $error['code'] ?? null,
                'device_id' => $diagnostic->device_id,
                'business_id' => $businessId,
                'client_app_version' => $clientAppVersion,
                'client_sync_version' => $clientSyncVersion,
            ]);
        }

        return $diagnostic;
    }

    private function resolveDeviceId(Request $request): ?string
    {
        $deviceId = $request->input('device.id') ?: $request->input('device_id');

        return is_string($deviceId) && trim($deviceId) !== '' ? trim($deviceId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestMeta(Request $request): array
    {
        $changes = $request->input('changes', []);
        $changes = is_array($changes) ? $changes : [];

        $entityTypes = collect($changes)
            ->map(static fn (mixed $change): ?string => is_array($change) && is_string($change['entity_type'] ?? null)
                ? trim((string) $change['entity_type'])
                : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_filter([
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255),
            'cursor' => $request->input('cursor'),
            'limit' => $request->input('limit'),
            'change_count' => count($changes),
            'entity_types' => $entityTypes,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }
}

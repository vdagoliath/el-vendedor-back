<?php

namespace App\Http\Middleware;

use App\Support\Sync\SyncCompatibility;
use App\Support\Sync\SyncDiagnosticsRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HandleSyncRequest
{
    public function __construct(
        private readonly SyncCompatibility $syncCompatibility,
        private readonly SyncDiagnosticsRecorder $syncDiagnosticsRecorder
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $stage = $this->resolveStage($request);
        $requestId = (string) Str::uuid();
        $request->attributes->set('sync_request_id', $requestId);

        $compatibilityFailure = $this->syncCompatibility->evaluateRequest($request, $stage);
        if ($compatibilityFailure !== null) {
            $payload = [
                'message' => $compatibilityFailure['message'],
                'error' => [
                    'code' => $compatibilityFailure['code'],
                    'stage' => $stage,
                    'retryable' => false,
                    'request_id' => $requestId,
                ],
                'compatibility' => $compatibilityFailure['compatibility'],
            ];

            $response = response()->json($payload, $compatibilityFailure['http_status']);
            $this->appendSyncHeaders($response, $requestId);

            $this->syncDiagnosticsRecorder->record(
                $request,
                $stage,
                'rejected',
                $compatibilityFailure['http_status'],
                [
                    'compatibility' => $compatibilityFailure['compatibility'],
                ],
                [
                    'code' => $compatibilityFailure['code'],
                    'message' => $compatibilityFailure['message'],
                ]
            );

            return $response;
        }

        try {
            $response = $next($request);
        } catch (Throwable $throwable) {
            $httpStatus = method_exists($throwable, 'getStatusCode')
                ? (int) $throwable->getStatusCode()
                : 500;

            $this->syncDiagnosticsRecorder->record(
                $request,
                $stage,
                'failed',
                $httpStatus,
                [],
                [
                    'code' => 'sync_unhandled_exception',
                    'message' => $throwable->getMessage(),
                ]
            );

            throw $throwable;
        }

        $status = $this->resolveResponseStatus($response);
        $error = $status === 'success'
            ? null
            : [
                'code' => $this->resolveResponseErrorCode($response),
                'message' => $this->extractResponseMessage($response),
            ];

        $responseMeta = $this->extractResponseMeta($response);
        $this->syncDiagnosticsRecorder->record(
            $request,
            $stage,
            $status,
            $response->getStatusCode(),
            $responseMeta,
            $error
        );

        $this->augmentJsonResponse($response, $requestId);
        $this->appendSyncHeaders($response, $requestId);

        return $response;
    }

    private function resolveStage(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return Str::afterLast($routeName, '.');
    }

    private function appendSyncHeaders(Response $response, string $requestId): void
    {
        $response->headers->set('X-Sync-Request-Id', $requestId);
        $response->headers->set('X-Sync-Version', (string) $this->syncCompatibility->currentProtocolVersion());

        $serverAppVersion = $this->syncCompatibility->currentAppVersion();
        if ($serverAppVersion !== null) {
            $response->headers->set('X-Server-App-Version', $serverAppVersion);
        }
    }

    private function augmentJsonResponse(Response $response, string $requestId): void
    {
        if (! $response instanceof JsonResponse) {
            return;
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return;
        }

        $meta = Arr::get($payload, 'meta', []);
        if (! is_array($meta)) {
            $meta = [];
        }

        $payload['meta'] = array_merge($meta, [
            'request_id' => $requestId,
            'server_sync_version' => $this->syncCompatibility->currentProtocolVersion(),
            'server_app_version' => $this->syncCompatibility->currentAppVersion(),
        ]);

        $response->setData($payload);
    }

    private function resolveResponseStatus(Response $response): string
    {
        if ($response->getStatusCode() >= 400) {
            return 'failed';
        }

        if (! $response instanceof JsonResponse) {
            return 'success';
        }

        $payload = $response->getData(true);
        $rejectedCount = (int) Arr::get($payload, 'meta.rejected_count', 0);

        return $rejectedCount > 0 ? 'partial_failure' : 'success';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractResponseMeta(Response $response): array
    {
        if (! $response instanceof JsonResponse) {
            return [];
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return [];
        }

        return array_filter([
            'meta' => Arr::get($payload, 'meta'),
            'accepted_count' => count(Arr::get($payload, 'accepted', [])),
            'duplicate_count' => count(Arr::get($payload, 'duplicates', [])),
            'rejected_count' => count(Arr::get($payload, 'rejected', [])),
            'conflict_count' => count(Arr::get($payload, 'conflicts', [])),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function resolveResponseErrorCode(Response $response): string
    {
        if (! $response instanceof JsonResponse) {
            return 'sync_http_error';
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return 'sync_http_error';
        }

        return (string) (Arr::get($payload, 'error.code')
            ?? Arr::get($payload, 'code')
            ?? ($response->getStatusCode() >= 500 ? 'sync_server_error' : 'sync_http_error'));
    }

    private function extractResponseMessage(Response $response): string
    {
        if (! $response instanceof JsonResponse) {
            return 'Sync request failed.';
        }

        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return 'Sync request failed.';
        }

        return (string) (Arr::get($payload, 'message') ?? 'Sync request failed.');
    }
}

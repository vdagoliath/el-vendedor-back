<?php

namespace App\Support\Sync;

use Illuminate\Http\Request;

class SyncCompatibility
{
    public function currentProtocolVersion(): int
    {
        return (int) config('sync.protocol_version', 1);
    }

    /**
     * @return array<int, int>
     */
    public function supportedProtocolVersions(): array
    {
        $versions = config('sync.supported_protocol_versions', [$this->currentProtocolVersion()]);

        return array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            is_array($versions) ? $versions : [$versions]
        )));
    }

    public function currentAppVersion(): ?string
    {
        $value = $this->normalizeString(config('sync.current_app_version'));

        return $value ?: $this->normalizeString(config('app.version'));
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrapPayload(): array
    {
        $policy = $this->appPolicy();
        $requiredAppVersion = $this->requiredAppVersion();
        $minimumAppVersion = $this->minimumAppVersion();
        $compatibleAppVersions = $this->compatibleAppVersions();

        return [
            'policy' => $policy,
            'enforced' => $this->isAppPolicyEnforced(),
            'server_app_version' => $this->currentAppVersion(),
            'required_app_version' => $requiredAppVersion,
            'minimum_app_version' => $minimumAppVersion,
            'compatible_app_versions' => $compatibleAppVersions,
            'server_sync_version' => $this->currentProtocolVersion(),
            'supported_sync_versions' => $this->supportedProtocolVersions(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function evaluateRequest(Request $request, string $stage): ?array
    {
        $clientSyncVersion = $this->clientSyncVersion($request);
        $clientAppVersion = $this->clientAppVersion($request);
        $compatibility = $this->bootstrapPayload();
        $compatibility['client_sync_version'] = $clientSyncVersion;
        $compatibility['client_app_version'] = $clientAppVersion;
        $compatibility['stage'] = $stage;

        if ($clientSyncVersion === null) {
            return [
                'http_status' => 409,
                'code' => 'sync_protocol_version_missing',
                'message' => 'La solicitud de sincronizacion no informa la version del protocolo. Actualiza la aplicacion antes de volver a sincronizar.',
                'compatibility' => $compatibility,
            ];
        }

        if (! in_array($clientSyncVersion, $this->supportedProtocolVersions(), true)) {
            return [
                'http_status' => 409,
                'code' => 'sync_protocol_version_mismatch',
                'message' => 'La version del protocolo de sincronizacion no es compatible con este servidor.',
                'compatibility' => $compatibility,
            ];
        }

        if (! $this->isAppPolicyEnforced()) {
            return null;
        }

        if ($clientAppVersion === null) {
            return [
                'http_status' => 409,
                'code' => 'sync_client_app_version_missing',
                'message' => 'La solicitud de sincronizacion no informa la version de la aplicacion. Actualiza la app antes de continuar.',
                'compatibility' => $compatibility,
            ];
        }

        return match ($this->appPolicy()) {
            'same_version' => $this->evaluateSameVersionPolicy($clientAppVersion, $compatibility),
            'minimum_version' => $this->evaluateMinimumVersionPolicy($clientAppVersion, $compatibility),
            'explicit_list' => $this->evaluateExplicitListPolicy($clientAppVersion, $compatibility),
            default => null,
        };
    }

    public function clientAppVersion(Request $request): ?string
    {
        return $this->normalizeString(
            $request->header('X-Client-App-Version')
            ?? data_get($request->input('device'), 'app_version')
        );
    }

    public function clientSyncVersion(Request $request): ?int
    {
        $value = $this->normalizeString($request->header('X-Sync-Version'));

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    public function appPolicy(): string
    {
        $policy = strtolower((string) config('sync.app_policy', 'same_version'));

        return in_array($policy, ['same_version', 'minimum_version', 'explicit_list', 'none'], true)
            ? $policy
            : 'same_version';
    }

    public function requiredAppVersion(): ?string
    {
        return $this->normalizeString(config('sync.required_app_version')) ?: $this->currentAppVersion();
    }

    public function minimumAppVersion(): ?string
    {
        return $this->normalizeString(config('sync.minimum_app_version'));
    }

    /**
     * @return array<int, string>
     */
    public function compatibleAppVersions(): array
    {
        $versions = config('sync.compatible_app_versions', []);

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizeString($value),
            is_array($versions) ? $versions : [$versions]
        )));
    }

    public function isAppPolicyEnforced(): bool
    {
        return match ($this->appPolicy()) {
            'same_version' => $this->requiredAppVersion() !== null,
            'minimum_version' => $this->minimumAppVersion() !== null,
            'explicit_list' => $this->compatibleAppVersions() !== [],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $compatibility
     * @return array<string, mixed>|null
     */
    private function evaluateSameVersionPolicy(string $clientAppVersion, array $compatibility): ?array
    {
        $requiredVersion = $this->requiredAppVersion();

        if ($requiredVersion === null || $clientAppVersion === $requiredVersion) {
            return null;
        }

        return [
            'http_status' => 409,
            'code' => 'sync_app_version_mismatch',
            'message' => 'La version de la aplicacion no coincide con la requerida por el servidor para sincronizar.',
            'compatibility' => $compatibility,
        ];
    }

    /**
     * @param  array<string, mixed>  $compatibility
     * @return array<string, mixed>|null
     */
    private function evaluateMinimumVersionPolicy(string $clientAppVersion, array $compatibility): ?array
    {
        $minimumVersion = $this->minimumAppVersion();

        if ($minimumVersion === null || version_compare($clientAppVersion, $minimumVersion, '>=')) {
            return null;
        }

        return [
            'http_status' => 409,
            'code' => 'sync_app_version_too_old',
            'message' => 'La version de la aplicacion es demasiado antigua para sincronizar con este servidor.',
            'compatibility' => $compatibility,
        ];
    }

    /**
     * @param  array<string, mixed>  $compatibility
     * @return array<string, mixed>|null
     */
    private function evaluateExplicitListPolicy(string $clientAppVersion, array $compatibility): ?array
    {
        if (in_array($clientAppVersion, $this->compatibleAppVersions(), true)) {
            return null;
        }

        return [
            'http_status' => 409,
            'code' => 'sync_app_version_not_allowed',
            'message' => 'La version de la aplicacion no esta autorizada para sincronizar con este servidor.',
            'compatibility' => $compatibility,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}

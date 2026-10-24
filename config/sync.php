<?php

return [
    'protocol_version' => (int) env('SYNC_PROTOCOL_VERSION', 1),
    'supported_protocol_versions' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('SYNC_SUPPORTED_PROTOCOL_VERSIONS', (string) env('SYNC_PROTOCOL_VERSION', '1')))
    ), static fn (string $value): bool => $value !== '')),

    'app_policy' => env('SYNC_APP_COMPATIBILITY_POLICY', 'same_version'),
    'current_app_version' => env('SYNC_CURRENT_APP_VERSION', env('APP_VERSION')),
    'required_app_version' => env('SYNC_REQUIRED_APP_VERSION', env('SYNC_CURRENT_APP_VERSION', env('APP_VERSION'))),
    'minimum_app_version' => env('SYNC_MINIMUM_APP_VERSION'),
    'compatible_app_versions' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('SYNC_COMPATIBLE_APP_VERSIONS', ''))
    ), static fn (string $value): bool => $value !== '')),
];

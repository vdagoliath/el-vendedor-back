<?php

namespace App\Support\Sync;

class ContactPayloadNormalizer
{
    /**
     * Normalize the incoming entity type for contact-like records.
     */
    public function normalizeEntityType(string $entityType): string
    {
        $normalized = strtolower(trim($entityType));

        return in_array($normalized, ['contacts', 'providers', 'suppliers', 'vendors', 'customers', 'clients'], true)
            ? 'contacts'
            : $normalized;
    }

    /**
     * Return the queryable entity types for the requested contact-like record.
     *
     * @return array<int, string>
     */
    public function queryEntityTypes(string $entityType): array
    {
        return $this->normalizeEntityType($entityType) === 'contacts'
            ? ['contacts', 'providers', 'suppliers', 'vendors', 'customers', 'clients']
            : [$entityType];
    }

    /**
     * Normalize a contact payload coming from sync clients.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $name = $this->firstFilledString([
            $payload['name'] ?? null,
            $payload['display_name'] ?? null,
            $payload['displayName'] ?? null,
            $payload['full_name'] ?? null,
            $payload['fullName'] ?? null,
            $payload['business_name'] ?? null,
            $payload['businessName'] ?? null,
            $payload['company_name'] ?? null,
            $payload['companyName'] ?? null,
            $payload['supplier_name'] ?? null,
            $payload['supplierName'] ?? null,
            $payload['provider_name'] ?? null,
            $payload['providerName'] ?? null,
            $payload['customer_name'] ?? null,
            $payload['customerName'] ?? null,
        ]);

        if ($name !== null) {
            $payload['name'] = $name;
        }

        $type = $this->normalizeType($payload);

        if ($type !== null) {
            $payload['type'] = $type;
        }

        return $payload;
    }

    /**
     * Resolve the contact display name from a normalized or legacy payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveName(array $payload, string $fallback): string
    {
        $normalized = $this->normalizePayload($payload);

        return trim((string) ($normalized['name'] ?? $fallback)) ?: $fallback;
    }

    /**
     * Infer a contact type from entity aliases when the payload does not define one.
     */
    public function inferTypeFromEntityType(string $entityType): ?string
    {
        return match (strtolower(trim($entityType))) {
            'providers', 'suppliers', 'vendors' => 'supplier',
            'customers', 'clients' => 'customer',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeType(array $payload): ?string
    {
        if (($payload['is_supplier'] ?? false) === true || ($payload['isSupplier'] ?? false) === true) {
            return 'supplier';
        }

        if (($payload['is_customer'] ?? false) === true || ($payload['isCustomer'] ?? false) === true) {
            return 'customer';
        }

        $rawType = $this->firstFilledString([
            $payload['type'] ?? null,
            $payload['contact_type'] ?? null,
            $payload['contactType'] ?? null,
            $payload['kind'] ?? null,
            $payload['role'] ?? null,
            $payload['category'] ?? null,
        ]);

        if ($rawType === null) {
            return null;
        }

        return match (strtolower($rawType)) {
            'supplier', 'suppliers', 'provider', 'providers', 'vendor', 'vendors', 'proveedor', 'proveedores' => 'supplier',
            'customer', 'customers', 'client', 'clients', 'cliente', 'clientes' => 'customer',
            default => strtolower($rawType),
        };
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilledString(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = trim($value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}

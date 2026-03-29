<?php

namespace App\Support\Licensing;

use App\Models\Business;
use App\Models\LicensePricingConfig;
use App\Models\LicensePricingRule;
use App\Models\SyncReceivedEvent;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BusinessLicensePricingResolver
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $config = $this->getConfig();
        $rules = $this->getRules();

        return [
            'operationWindowDays' => (int) ($config->active_pos_operation_window_days ?? 30),
            'conditions' => [
                'currency' => $rules->first()?->currency ?? 'CUP',
                'activePosDefinition' => 'Un punto de venta activo es aquel que tiene empleados asignados y ventas registradas en la ventana reciente configurada.',
                'activeOwnerDefinition' => 'Solo cuentan los dueños activos vinculados al negocio.',
            ],
            'rules' => $rules->map(fn (LicensePricingRule $rule): array => [
                'id' => (string) $rule->getKey(),
                'name' => $rule->name,
                'description' => $rule->description,
                'currency' => $rule->currency,
                'monthlyPrice' => (float) $rule->monthly_price,
                'minActivePos' => (int) ($rule->min_active_pos ?? 0),
                'maxActivePos' => $rule->max_active_pos !== null ? (int) $rule->max_active_pos : null,
                'minActiveOwners' => $rule->min_active_owners !== null ? (int) $rule->min_active_owners : null,
                'maxActiveOwners' => $rule->max_active_owners !== null ? (int) $rule->max_active_owners : null,
                'sortOrder' => (int) ($rule->sort_order ?? 0),
            ])->values()->all(),
            'updatedAt' => $this->resolveCatalogUpdatedAt($config, $rules)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(Business $business): array
    {
        $config = $this->getConfig();
        $rules = $this->getRules();
        $operationWindowDays = (int) ($config->active_pos_operation_window_days ?? 30);
        $activeOwnerCount = (int) $business->owners()->wherePivot('is_active', true)->count();
        $activePosCount = $this->resolveActivePosCount($business, $operationWindowDays);
        $matchedRule = $this->resolveMatchingRule($rules, $activePosCount, $activeOwnerCount);

        return [
            'businessId' => $business->id,
            'currency' => $matchedRule?->currency ?? 'CUP',
            'monthlyPrice' => $matchedRule ? (float) $matchedRule->monthly_price : 0.0,
            'activePosCount' => $activePosCount,
            'activeOwnerCount' => $activeOwnerCount,
            'operationWindowDays' => $operationWindowDays,
            'ruleId' => $matchedRule ? (string) $matchedRule->getKey() : null,
            'ruleLabel' => $matchedRule?->name,
            'ruleDescription' => $matchedRule?->description,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    private function getConfig(): LicensePricingConfig
    {
        return LicensePricingConfig::query()->firstOrCreate([], [
            'active_pos_operation_window_days' => 30,
        ]);
    }

    /**
     * @return Collection<int, LicensePricingRule>
     */
    private function getRules(): Collection
    {
        return LicensePricingRule::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function resolveMatchingRule(Collection $rules, int $activePosCount, int $activeOwnerCount): ?LicensePricingRule
    {
        /** @var LicensePricingRule|null $matched */
        $matched = $rules->first(function (LicensePricingRule $rule) use ($activePosCount, $activeOwnerCount): bool {
            $matchesPos = $activePosCount >= (int) ($rule->min_active_pos ?? 0)
                && ($rule->max_active_pos === null || $activePosCount <= (int) $rule->max_active_pos);
            $matchesOwners = ($rule->min_active_owners === null || $activeOwnerCount >= (int) $rule->min_active_owners)
                && ($rule->max_active_owners === null || $activeOwnerCount <= (int) $rule->max_active_owners);

            return $matchesPos && $matchesOwners;
        });

        if ($matched) {
            return $matched;
        }

        return $rules->sortByDesc('sort_order')->first();
    }

    private function resolveActivePosCount(Business $business, int $operationWindowDays): int
    {
        $pointsOfSale = $this->syncStore->latestPayloads($business, 'points_of_sale');
        if ($pointsOfSale->isEmpty()) {
            return 0;
        }

        $cutoff = now()->subDays(max($operationWindowDays, 1));
        $recentSales = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('entity_type', 'sales')
            ->where('status', 'applied')
            ->where('operation', '!=', 'delete')
            ->where('occurred_at', '>=', $cutoff)
            ->orderByDesc('occurred_at')
            ->get();

        $recentPosIds = $recentSales
            ->map(fn (SyncReceivedEvent $event): string => trim((string) data_get($event->payload, 'posId', data_get($event->payload, 'pos'))))
            ->filter()
            ->unique()
            ->values();

        return $pointsOfSale
            ->filter(function (array $pos) use ($recentPosIds): bool {
                $employees = collect($pos['employees'] ?? [])
                    ->filter(fn (mixed $employee): bool => is_array($employee) && filled($employee['_id'] ?? null));

                if ($employees->isEmpty()) {
                    return false;
                }

                $entityId = trim((string) ($pos['_entity_id'] ?? ''));
                if ($entityId === '') {
                    return false;
                }

                return $recentPosIds->contains($entityId);
            })
            ->count();
    }

    private function resolveCatalogUpdatedAt(LicensePricingConfig $config, Collection $rules): ?Carbon
    {
        $dates = collect([$config->updated_at])
            ->merge($rules->pluck('updated_at'))
            ->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        return Carbon::parse($dates->max());
    }
}

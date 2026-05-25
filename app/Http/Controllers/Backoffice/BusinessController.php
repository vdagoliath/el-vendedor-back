<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreBusinessRequest;
use App\Models\Business;
use App\Models\LicensePricingConfig;
use App\Models\LicensePricingRule;
use App\Models\User;
use App\Support\Licensing\BusinessLicensePricingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessLicensePricingResolver $pricingResolver
    ) {}

    /**
     * Show the backoffice business management page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->canPrepareBusinessesForSync(), 403);

        $businesses = Business::query()
            ->visibleTo($user)
            ->with([
                'owners' => fn ($query) => $query
                    ->wherePivot('is_active', true)
                    ->select('users.id', 'users.name', 'users.email', 'users.current_business_id')
                    ->orderBy('users.name'),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $currentBusiness = $businesses->firstWhere('id', $user->current_business_id);

        return Inertia::render('backoffice/Businesses', [
            'businesses' => $this->mapBusinesses($businesses, $user->current_business_id),
            'currentBusiness' => $currentBusiness ? $this->mapBusiness($currentBusiness, $user->current_business_id) : null,
            'licensePricing' => [
                'catalog' => $this->pricingResolver->catalog(),
                'quote' => $currentBusiness ? $this->pricingResolver->quote($currentBusiness) : null,
                'config' => [
                    'id' => LicensePricingConfig::query()->value('id'),
                    'active_pos_operation_window_days' => LicensePricingConfig::query()->value('active_pos_operation_window_days') ?? 30,
                ],
                'rules' => LicensePricingRule::query()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (LicensePricingRule $rule): array => [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'description' => $rule->description,
                        'currency' => $rule->currency,
                        'monthly_price' => (float) $rule->monthly_price,
                        'min_active_pos' => (int) ($rule->min_active_pos ?? 0),
                        'max_active_pos' => $rule->max_active_pos,
                        'min_active_owners' => $rule->min_active_owners,
                        'max_active_owners' => $rule->max_active_owners,
                        'sort_order' => (int) ($rule->sort_order ?? 0),
                        'is_active' => (bool) $rule->is_active,
                    ])
                    ->all(),
            ],
            'generatedSyncToken' => [
                'token' => $request->session()->get('generated_sync_token'),
                'owner_email' => $request->session()->get('generated_sync_token_owner_email'),
                'warning' => $request->session()->get('generated_sync_token_warning'),
            ],
        ]);
    }

    /**
     * Store a newly created business.
     */
    public function store(StoreBusinessRequest $request): RedirectResponse
    {
        abort_unless($request->user()->canPrepareBusinessesForSync(), 403);

        $business = Business::query()->create([
            'name' => $request->string('name')->toString(),
            'slug' => $this->resolveSlug($request),
            'address' => null,
            'country' => 'CU',
            'province' => $request->string('province')->toString() ?: null,
            'municipality' => $request->string('municipality')->toString() ?: null,
            'street' => $request->string('street')->toString() ?: null,
            'phone' => $request->string('phone')->toString() ?: null,
            'default_currency' => $request->string('default_currency')->toString(),
            'policies' => $this->businessPoliciesFromRequest($request),
            'is_active' => true,
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        $request->user()->switchCurrentBusiness($business);

        return to_route('backoffice.businesses.index')->with('success', 'Negocio creado correctamente y listo para configurarse.');
    }

    /**
     * Update an existing business profile.
     */
    public function update(StoreBusinessRequest $request, Business $business): RedirectResponse
    {
        abort_unless($request->user()->canManageBusinessFromBackoffice($business), 403);

        $business->forceFill([
            'name' => $request->string('name')->toString(),
            'slug' => $this->resolveSlug($request, $business),
            'address' => null,
            'country' => $business->country ?? 'CU',
            'province' => $request->string('province')->toString() ?: null,
            'municipality' => $request->string('municipality')->toString() ?: null,
            'street' => $request->string('street')->toString() ?: null,
            'phone' => $request->string('phone')->toString() ?: null,
            'default_currency' => $request->string('default_currency')->toString(),
            'policies' => $this->businessPoliciesFromRequest($request, (array) ($business->policies ?? [])),
        ])->save();

        return to_route('backoffice.businesses.index')->with('success', 'Los datos del negocio se actualizaron correctamente.');
    }

    /**
     * Deactivate a business so it cannot be used for sync or backoffice access.
     */
    public function destroy(Request $request, Business $business): RedirectResponse
    {
        abort_unless($request->user()->canManageBusinessFromBackoffice($business), 403);

        $business->forceFill(['is_active' => false])->save();

        User::query()
            ->where('current_business_id', $business->getKey())
            ->update(['current_business_id' => null]);

        return to_route('backoffice.businesses.index')->with('success', 'El negocio fue desactivado.');
    }

    /**
     * Reactivate a previously deactivated business.
     */
    public function restore(Request $request, Business $business): RedirectResponse
    {
        abort_unless($request->user()->canManageBusinessFromBackoffice($business), 403);

        $business->forceFill(['is_active' => true])->save();

        return to_route('backoffice.businesses.index')->with('success', 'El negocio fue reactivado.');
    }

    /**
     * Generate a sync token for the current business and reveal it once.
     */
    public function issueSyncToken(Request $request, Business $business): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && $user->canPrepareBusinessForSync($business), 403);

        $owner = $this->resolveSyncTokenOwner($business);

        if (! $owner) {
            return to_route('backoffice.businesses.index')->with('error', 'Este negocio no tiene un dueño activo para emitir el token de sincronización.');
        }

        $tokenName = 'sync-business-'.$business->getKey();

        $business->owners()
            ->wherePivot('is_active', true)
            ->get()
            ->each(fn (User $candidate) => $candidate->tokens()->where('name', $tokenName)->delete());

        $warning = null;

        if ($owner->current_business_id !== $business->id) {
            $owner->forceFill(['current_business_id' => $business->id])->save();
            $warning = 'El token se emitió usando el dueño '.$owner->email.'. Si ese dueño cambia luego su negocio activo, conviene regenerar este token.';
        }

        $token = $owner->createToken($tokenName)->plainTextToken;

        return to_route('backoffice.businesses.index')->with([
            'success' => 'Token de sincronización generado correctamente. Cópialo ahora porque solo se muestra una vez.',
            'generated_sync_token' => $token,
            'generated_sync_token_owner_email' => $owner->email,
            'generated_sync_token_warning' => $warning,
        ]);
    }

    /**
     * Map businesses for the page props.
     *
     * @param  Collection<int, Business>  $businesses
     * @return array<int, array<string, mixed>>
     */
    private function mapBusinesses(Collection $businesses, ?int $currentBusinessId): array
    {
        return $businesses
            ->map(fn (Business $business): array => $this->mapBusiness($business, $currentBusinessId))
            ->all();
    }

    /**
     * Map a business into a serializable payload.
     *
     * @return array<string, mixed>
     */
    private function mapBusiness(Business $business, ?int $currentBusinessId): array
    {
        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'address' => $business->address,
            'province' => $business->province,
            'municipality' => $business->municipality,
            'street' => $business->street,
            'phone' => $business->phone,
            'default_currency' => $business->default_currency,
            'policies' => array_merge($this->defaultBusinessPolicies(), (array) ($business->policies ?? [])),
            'owner_emails' => $business->owners
                ->pluck('email')
                ->filter()
                ->values()
                ->all(),
            'is_active' => $business->is_active,
            'current_user_role' => $business->pivot?->role,
            'membership_is_active' => $business->pivot ? (bool) $business->pivot->is_active : null,
            'is_current' => $business->id === $currentBusinessId,
        ];
    }

    /**
     * Build the policy payload shared with the mobile app through business_profile sync.
     *
     * @return array<string, mixed>
     */
    private function businessPoliciesFromRequest(StoreBusinessRequest $request, array $current = []): array
    {
        $base = array_merge($this->defaultBusinessPolicies(), $current);

        return array_merge($base, [
            'allowZeroStockSales' => $request->has('allow_zero_stock_sales')
                ? $request->boolean('allow_zero_stock_sales')
                : (bool) $base['allowZeroStockSales'],
            'enableDebtManagement' => $request->has('enable_debt_management')
                ? $request->boolean('enable_debt_management')
                : (bool) $base['enableDebtManagement'],
            'printSaleReceiptEnabled' => $request->has('print_sale_receipt_enabled')
                ? $request->boolean('print_sale_receipt_enabled')
                : (bool) $base['printSaleReceiptEnabled'],
            'productCodePrefix' => $request->has('product_code_prefix')
                ? $request->string('product_code_prefix')->toString()
                : (string) $base['productCodePrefix'],
            'productCodeDigits' => $request->has('product_code_digits')
                ? max(0, (int) $request->integer('product_code_digits'))
                : (int) $base['productCodeDigits'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultBusinessPolicies(): array
    {
        return [
            'allowZeroStockSales' => true,
            'enableDebtManagement' => true,
            'showPrice' => true,
            'showStock' => true,
            'showQrCodeGenerator' => true,
            'printSaleReceiptEnabled' => false,
            'productCodePrefix' => '',
            'productCodeDigits' => 0,
        ];
    }

    /**
     * Resolve the owner that will back the sync token.
     */
    private function resolveSyncTokenOwner(Business $business): ?User
    {
        $owners = $business->owners;

        if ($owners->isEmpty()) {
            return null;
        }

        return $owners
            ->sortBy([
                fn (User $owner) => $owner->current_business_id === $business->id ? 0 : 1,
                fn (User $owner) => mb_strtolower($owner->name ?? $owner->email ?? ''),
            ])
            ->first();
    }

    /**
     * Resolve a unique business slug.
     */
    private function resolveSlug(StoreBusinessRequest $request, ?Business $business = null): string
    {
        if ($request->filled('slug')) {
            return $request->string('slug')->toString();
        }

        $baseSlug = Str::slug($request->string('name')->toString());
        $slug = $baseSlug;
        $suffix = 2;

        while (Business::query()
            ->when($business, fn ($query) => $query->whereKeyNot($business->getKey()))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\BackofficeRole;
use App\Models\Business;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        if ($user) {
            $user->ensureCurrentBusiness();
            $user->loadMissing('currentBusiness');
        }

        $effectiveBackofficeRole = $user?->getEffectiveBackofficeRole();
        $accessibleBusinesses = collect();

        if ($user) {
            $accessibleBusinesses = $user->hasBackofficeRole()
                ? Business::query()->where('is_active', true)->orderBy('name')->get()
                : $user->businesses()->orderBy('businesses.name')->get();
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale ?? app()->getLocale(),
                    'backoffice_role' => $effectiveBackofficeRole?->value,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                    'is_platform_admin' => $user->is_platform_admin,
                    'backoffice' => [
                        'can_access' => $user->canAccessBackoffice(),
                        'can_manage_users' => $user->canManageBackofficeUsers(),
                        'can_prepare_businesses' => $user->canPrepareBusinessesForSync(),
                        'can_access_dashboard' => $user->canAccessBackofficeDashboard(),
                        'can_view_analytics' => $user->canViewBackofficeAnalytics(),
                        'role_label' => $effectiveBackofficeRole?->label(),
                    ],
                    'current_business' => $user->currentBusiness ? [
                        'id' => $user->currentBusiness->id,
                        'name' => $user->currentBusiness->name,
                        'slug' => $user->currentBusiness->slug,
                        'is_active' => $user->currentBusiness->is_active,
                    ] : null,
                    'businesses' => $accessibleBusinesses
                        ->map(fn (Business $business): array => [
                            'id' => $business->id,
                            'name' => $business->name,
                            'slug' => $business->slug,
                            'is_active' => $business->is_active,
                            'role' => $business->pivot?->role,
                            'membership_is_active' => (bool) $business->pivot?->is_active,
                        ])
                        ->all(),
                ] : null,
            ],
            'backofficeRoles' => collect(BackofficeRole::cases())
                ->map(fn (BackofficeRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->all(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}

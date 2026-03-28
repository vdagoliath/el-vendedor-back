<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreBusinessRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    /**
     * Show the backoffice business management page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->canPrepareBusinessesForSync(), 403);

        $businesses = Business::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('backoffice/Businesses', [
            'businesses' => $this->mapBusinesses($businesses, $user->current_business_id),
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
            'is_active' => true,
        ]);

        $request->user()->switchCurrentBusiness($business);

        return to_route('backoffice.businesses.index')->with('success', 'Negocio creado correctamente y listo para configurarse.');
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
            ->map(fn (Business $business): array => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'is_active' => $business->is_active,
                'current_user_role' => $business->pivot?->role,
                'membership_is_active' => $business->pivot ? (bool) $business->pivot->is_active : null,
                'is_current' => $business->id === $currentBusinessId,
            ])
            ->all();
    }

    /**
     * Resolve a unique business slug.
     */
    private function resolveSlug(StoreBusinessRequest $request): string
    {
        if ($request->filled('slug')) {
            return $request->string('slug')->toString();
        }

        $baseSlug = Str::slug($request->string('name')->toString());
        $slug = $baseSlug;
        $suffix = 2;

        while (Business::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

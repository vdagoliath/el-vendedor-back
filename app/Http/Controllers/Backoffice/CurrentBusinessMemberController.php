<?php

namespace App\Http\Controllers\Backoffice;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreCurrentBusinessMemberRequest;
use App\Http\Requests\Backoffice\UpdateCurrentBusinessMemberPasswordRequest;
use App\Http\Requests\Backoffice\UpdateCurrentBusinessMemberRequest;
use App\Models\Business;
use App\Models\User;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CurrentBusinessMemberController extends Controller
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    /**
     * Show the team management page for the current business.
     */
    public function index(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        return Inertia::render('backoffice/Team', [
            'currentBusiness' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ],
            'members' => $this->mapMembers($business->users()->orderBy('users.name')->get()),
            'employees' => $this->syncStore->latestPayloads($business, 'employees')
                ->sortBy(fn (array $employee): string => strtolower(trim((string) ($employee['name'] ?? ''))))
                ->values()
                ->map(fn (array $employee): array => [
                    'id' => (string) ($employee['_entity_id'] ?? ''),
                    'name' => trim((string) ($employee['name'] ?? '')),
                    'mobile' => trim((string) ($employee['mobile'] ?? '')),
                    'updated_at' => $employee['_updated_at'] ?? null,
                ])
                ->all(),
        ]);
    }

    /**
     * Store a newly created or attached member for the current business.
     */
    public function store(StoreCurrentBusinessMemberRequest $request): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $member = $request->filled('user_id')
            ? User::query()->findOrFail($request->integer('user_id'))
            : User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
            ]);

        $membership = [
            'role' => $request->enum('role', BusinessRole::class)?->value ?? BusinessRole::Employee->value,
            'is_active' => true,
        ];

        if ($business->users()->whereKey($member->getKey())->exists()) {
            $business->users()->updateExistingPivot($member->getKey(), $membership);
        } else {
            $business->users()->attach($member, $membership);
        }

        return to_route('backoffice.team.index')->with('success', 'Miembro agregado correctamente.');
    }

    /**
     * Update the profile and membership of a business member.
     */
    public function update(UpdateCurrentBusinessMemberRequest $request, User $member): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);
        abort_unless($business->users()->whereKey($member->getKey())->exists(), 404);

        $member->forceFill([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
        ])->save();

        $business->users()->updateExistingPivot($member->getKey(), [
            'role' => $request->enum('role', BusinessRole::class)->value,
            'is_active' => $request->boolean('is_active'),
        ]);

        return to_route('backoffice.team.index')->with('success', 'Los datos del miembro fueron actualizados.');
    }

    /**
     * Detach a member from the current business.
     */
    public function destroy(Request $request, User $member): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);
        abort_unless($business->users()->whereKey($member->getKey())->exists(), 404);

        if ($member->getKey() === $request->user()->getKey()) {
            return to_route('backoffice.team.index')->with('error', 'No puedes eliminarte a ti mismo del negocio.');
        }

        $business->users()->detach($member->getKey());

        if ($member->current_business_id === $business->getKey()) {
            $member->forceFill(['current_business_id' => null])->save();
        }

        return to_route('backoffice.team.index')->with('success', 'El miembro fue desvinculado del negocio.');
    }

    /**
     * Replace the password of a business member.
     */
    public function updatePassword(UpdateCurrentBusinessMemberPasswordRequest $request, User $member): RedirectResponse
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);
        abort_unless($business->users()->whereKey($member->getKey())->exists(), 404);

        $member->forceFill(['password' => $request->string('password')->toString()])->save();

        return to_route('backoffice.team.index')->with('success', 'La contraseña del miembro fue actualizada.');
    }

    /**
     * Map the business members for the page props.
     *
     * @param  Collection<int, User>  $members
     * @return array<int, array<string, mixed>>
     */
    private function mapMembers(Collection $members): array
    {
        return $members
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot?->role,
                'membership_is_active' => (bool) $member->pivot?->is_active,
                'backoffice_role' => $member->getEffectiveBackofficeRole()?->value,
                'backoffice_role_label' => $member->getEffectiveBackofficeRole()?->label(),
            ])
            ->all();
    }
}

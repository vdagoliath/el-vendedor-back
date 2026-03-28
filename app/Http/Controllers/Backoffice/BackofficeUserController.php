<?php

namespace App\Http\Controllers\Backoffice;

use App\Enums\BackofficeRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\StoreBackofficeUserRequest;
use App\Http\Requests\Backoffice\UpdateBackofficeUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BackofficeUserController extends Controller
{
    /**
     * Show the backoffice access management page.
     */
    public function index(): Response
    {
        $users = User::query()
            ->whereNotNull('backoffice_role')
            ->orWhere('is_platform_admin', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('backoffice/Access', [
            'users' => $this->mapUsers($users),
            'stats' => [
                'total' => $users->count(),
                'super_admins' => $users->filter(fn (User $user): bool => $user->isBackofficeSuperAdmin())->count(),
                'implementers' => $users->filter(fn (User $user): bool => $user->isBackofficeImplementer())->count(),
            ],
        ]);
    }

    /**
     * Create or grant backoffice access to a user.
     */
    public function store(StoreBackofficeUserRequest $request): RedirectResponse
    {
        $role = $request->backofficeRole();
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (! $user) {
            if (! $request->filled('name') || ! $request->filled('password')) {
                throw ValidationException::withMessages([
                    'name' => 'El nombre es obligatorio cuando se crea un nuevo usuario de backoffice.',
                    'password' => 'La contraseña es obligatoria cuando se crea un nuevo usuario de backoffice.',
                ]);
            }

            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'locale' => app()->getLocale(),
            ]);
        } elseif ($request->filled('name')) {
            $user->forceFill([
                'name' => $request->string('name')->toString(),
            ])->save();
        }

        $user->setBackofficeRole($role);

        return to_route('backoffice.access.index')->with('success', 'El acceso de backoffice fue guardado correctamente.');
    }

    /**
     * Update or revoke the backoffice role from an existing user.
     */
    public function update(UpdateBackofficeUserRoleRequest $request, User $user): RedirectResponse
    {
        $nextRole = $request->backofficeRole();
        $currentRole = $user->getEffectiveBackofficeRole();

        if ($currentRole === BackofficeRole::SuperAdmin && $nextRole !== BackofficeRole::SuperAdmin && $this->effectiveSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'backoffice_role' => 'Debe existir al menos un administrador supremo en el backoffice.',
            ]);
        }

        $user->setBackofficeRole($nextRole);

        return to_route('backoffice.access.index')->with('success', 'El rol de backoffice fue actualizado correctamente.');
    }

    /**
     * Count users that currently resolve as super admins.
     */
    private function effectiveSuperAdminCount(): int
    {
        return User::query()
            ->get()
            ->filter(fn (User $user): bool => $user->isBackofficeSuperAdmin())
            ->count();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    private function mapUsers(Collection $users): array
    {
        return $users
            ->map(function (User $user): array {
                $role = $user->getEffectiveBackofficeRole();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'backoffice_role' => $role?->value,
                    'role_label' => $role?->label(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}

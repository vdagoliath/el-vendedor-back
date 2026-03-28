<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\SyncReceivedEvent;
use App\Support\Backoffice\CurrentBusinessSyncStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessPreparationController extends Controller
{
    public function __construct(
        private readonly CurrentBusinessSyncStore $syncStore
    ) {}

    /**
     * Show the sync readiness checklist for the current business.
     */
    public function index(Request $request): Response
    {
        $business = $request->attributes->get('currentBusiness');

        abort_unless($business instanceof Business && $request->user()->canPrepareBusinessForSync($business), 403);

        $productCount = Product::query()->where('business_id', $business->id)->count();
        $activeMemberCount = $business->users()->wherePivot('is_active', true)->count();
        $syncedEmployeeCount = $this->syncStore->latestPayloads($business, 'employees')->count();
        $latestSyncAt = SyncReceivedEvent::query()
            ->where('business_id', $business->id)
            ->where('status', 'applied')
            ->latest('occurred_at')
            ->first()?->occurred_at;

        $checklist = [
            [
                'key' => 'business_profile',
                'title' => 'Perfil del negocio completo',
                'description' => 'Nombre, slug, direccion, telefono y moneda deben estar definidos.',
                'is_ready' => filled($business->name)
                    && filled($business->slug)
                    && filled($business->address)
                    && filled($business->phone)
                    && filled($business->default_currency),
            ],
            [
                'key' => 'license',
                'title' => 'Licencia vigente',
                'description' => 'La licencia del negocio debe existir y estar vigente para salir a produccion.',
                'is_ready' => $business->license_expires_at?->isFuture() ?? false,
                'meta' => $business->license_expires_at?->toDateString(),
            ],
            [
                'key' => 'catalog',
                'title' => 'Catalogo inicial cargado',
                'description' => 'Debe existir al menos un producto listo para sincronizar con los dispositivos.',
                'is_ready' => $productCount > 0,
                'meta' => $productCount.' producto(s)',
            ],
            [
                'key' => 'team',
                'title' => 'Equipo configurado',
                'description' => 'Debe existir al menos un miembro del negocio o un empleado sincronizado.',
                'is_ready' => $activeMemberCount > 0 || $syncedEmployeeCount > 0,
                'meta' => ($activeMemberCount + $syncedEmployeeCount).' persona(s)',
            ],
            [
                'key' => 'sync_context',
                'title' => 'Contexto de sincronizacion preparado',
                'description' => 'El negocio debe estar activo y listo para recibir eventos de dispositivos.',
                'is_ready' => $business->is_active,
                'meta' => $latestSyncAt?->toIso8601String(),
            ],
        ];

        $readyItems = collect($checklist)->where('is_ready', true)->count();

        return Inertia::render('backoffice/Preparation', [
            'currentBusiness' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'address' => $business->address,
                'phone' => $business->phone,
                'default_currency' => $business->default_currency ?? 'CUP',
                'license_expires_at' => $business->license_expires_at?->toIso8601String(),
            ],
            'summary' => [
                'ready_items' => $readyItems,
                'total_items' => count($checklist),
                'products_count' => $productCount,
                'active_members_count' => $activeMemberCount,
                'synced_employees_count' => $syncedEmployeeCount,
                'latest_sync_at' => $latestSyncAt?->toIso8601String(),
                'is_ready' => $readyItems === count($checklist),
            ],
            'checklist' => $checklist,
        ]);
    }
}

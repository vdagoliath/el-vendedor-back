<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ClaimSellerTokenRequest;
use App\Http\Requests\Api\V1\Auth\CreateSellerInvitationRequest;
use App\Models\Business;
use App\Models\SellerInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerInvitationController extends Controller
{
    private const INVITATION_TTL_MINUTES = 15;

    /**
     * Owner creates an invitation for a specific seller device.
     * Requires ability 'sync:owner'.
     */
    public function store(CreateSellerInvitationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->ensureCurrentBusiness();
        $user->load('currentBusiness');

        /** @var Business|null $business */
        $business = $user->currentBusiness;

        if (! $business || ! $user->ownsBusiness($business)) {
            throw ValidationException::withMessages([
                'business' => ['Solo el dueño del negocio puede crear invitaciones.'],
            ]);
        }

        $employeeExternalId = $request->string('employee_external_id')->toString();
        $employeeName = $request->input('employee_name');

        $code = $this->generateUniqueCode();
        $expiresAt = now()->addMinutes(self::INVITATION_TTL_MINUTES);

        $invitation = SellerInvitation::query()->create([
            'business_id' => $business->id,
            'issued_by_user_id' => $user->id,
            'employee_external_id' => $employeeExternalId,
            'employee_name' => $employeeName,
            'code' => $code,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'invitation_code' => $invitation->code,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
            ],
            'employee_external_id' => $invitation->employee_external_id,
            'employee_name' => $invitation->employee_name,
        ], 201);
    }

    /**
     * Seller device exchanges an invitation code for a scoped sync token.
     * Public endpoint (no auth required — the code itself is the credential).
     */
    public function claim(ClaimSellerTokenRequest $request): JsonResponse
    {
        $code = $request->string('invitation_code')->toString();
        $deviceUuid = $request->string('device_uuid')->toString();
        $deviceName = $request->string('device_name')->toString();

        /** @var SellerInvitation|null $invitation */
        $invitation = SellerInvitation::query()->where('code', $code)->first();

        if (! $invitation || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'invitation_code' => ['Código de invitación inválido o expirado.'],
            ]);
        }

        /** @var Business $business */
        $business = $invitation->business()->firstOrFail();
        $issuer = $invitation->issuedBy()->firstOrFail();

        $result = DB::transaction(function () use ($invitation, $issuer, $business, $deviceUuid, $deviceName) {
            // Revocar tokens previos del mismo seller+device para no acumular.
            $issuer->tokens()
                ->where('employee_external_id', $invitation->employee_external_id)
                ->where('device_uuid', $deviceUuid)
                ->delete();

            $newAccessToken = $issuer->createToken($deviceName, ['sync:seller']);
            $newAccessToken->accessToken->forceFill([
                'business_id' => $business->id,
                'employee_external_id' => $invitation->employee_external_id,
                'device_uuid' => $deviceUuid,
            ])->save();

            $invitation->forceFill([
                'used_at' => now(),
                'used_device_uuid' => $deviceUuid,
                'issued_token_id' => $newAccessToken->accessToken->id,
            ])->save();

            return $newAccessToken;
        });

        return response()->json([
            'token' => $result->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => ['sync:seller'],
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'default_currency' => $business->default_currency ?? 'CUP',
            ],
            'employee_external_id' => $invitation->employee_external_id,
            'employee_name' => $invitation->employee_name,
        ]);
    }

    /**
     * Owner lists active/expired invitations and linked seller devices.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->ensureCurrentBusiness();
        $user->load('currentBusiness');

        /** @var Business|null $business */
        $business = $user->currentBusiness;

        if (! $business || ! $user->ownsBusiness($business)) {
            throw ValidationException::withMessages([
                'business' => ['Solo el dueño del negocio puede ver las invitaciones.'],
            ]);
        }

        $invitations = SellerInvitation::query()
            ->where('business_id', $business->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (SellerInvitation $invitation) => [
                'id' => $invitation->id,
                'code' => $invitation->code,
                'employee_external_id' => $invitation->employee_external_id,
                'employee_name' => $invitation->employee_name,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'used_at' => $invitation->used_at?->toIso8601String(),
                'used_device_uuid' => $invitation->used_device_uuid,
                'is_usable' => $invitation->isUsable(),
            ]);

        return response()->json(['data' => $invitations]);
    }

    private function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = strtoupper(Str::random(8));
            if (! SellerInvitation::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Fallback improbable — añadir timestamp para garantizar unicidad.
        return strtoupper(Str::random(6)).strtoupper(dechex(Carbon::now()->timestamp % 0xFFFF));
    }
}

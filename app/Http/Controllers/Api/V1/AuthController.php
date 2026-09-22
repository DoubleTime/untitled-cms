<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Marketplace\AiBoxBelongsToAnotherCustomer;
use App\Exceptions\Marketplace\AiBoxBlocked;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveAiBox;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Auth\LoginRequest as WebLoginRequest;
use App\Models\AiBox;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Marketplace\AiBoxService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token issue/revoke and identity for RPA-TOOL.
 *
 * Only Customer Users may sign in here; Team Members use the web admin and are
 * refused (the mirror image of the web login, which refuses Customer Users — see
 * App\Http\Requests\Auth\LoginRequest). The token is named after the motherboard
 * UUID it was issued for, which is how every later request resolves its AI Box.
 */
class AuthController extends Controller
{
    /** Deliberately the same message for an unknown email and a wrong password. */
    public const CREDENTIALS_MESSAGE = 'These credentials do not match our records.';

    public const INACTIVE_MESSAGE = 'This account has been deactivated.';

    public function __construct(private AiBoxService $boxes) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => self::CREDENTIALS_MESSAGE,
            ]);
        }

        if (! $user->is_active) {
            abort(403, self::INACTIVE_MESSAGE);
        }

        // Team Members hold no Customer and are refused here; a Customer User whose
        // Customer has been deactivated is refused too.
        $customer = $user->customer;

        if (! $user->isCustomerUser() || ! WebLoginRequest::isRpaToolOnly($user)
            || $customer === null || ! $customer->is_active) {
            abort(403, ResolveAiBox::ACCOUNT_REFUSED);
        }

        try {
            $box = $this->boxes->resolve(
                $customer,
                $data['motherboard_uuid'],
                $data['box_name'] ?? null,
                (string) $request->ip(),
                $user,
            );
        } catch (AiBoxBelongsToAnotherCustomer|AiBoxBlocked $e) {
            abort(403, $e->getMessage());
        }

        $expiresAt = now()->addDays((int) config('marketplace.token_ttl_days'));

        $token = $user->createToken($box->motherboard_uuid, ['*'], $expiresAt);

        ActivityLogger::log(
            'api_login',
            "RPA-TOOL login: {$user->email} on AI Box {$box->motherboard_uuid}",
            $box,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
        ] + $this->identityPayload($user, $box));
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof Model) {
            $token->delete();
        }

        ActivityLogger::log(
            'api_logout',
            'RPA-TOOL logout: '.($user?->email ?? 'unknown'),
        );

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $box = $request->attributes->get(ResolveAiBox::ATTRIBUTE);
        $token = $user->currentAccessToken();

        $expiresAt = $token instanceof Model ? $token->getAttribute('expires_at') : null;

        return response()->json($this->identityPayload($user, $box) + [
            'token_expires_at' => $expiresAt?->toISOString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function identityPayload(User $user, AiBox $box): array
    {
        $customer = $user->customer;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name,
                'company' => $customer?->company,
            ],
            'ai_box' => [
                'id' => $box->id,
                'motherboard_uuid' => $box->motherboard_uuid,
                'name' => $box->name,
                'status' => $box->status,
            ],
        ];
    }
}

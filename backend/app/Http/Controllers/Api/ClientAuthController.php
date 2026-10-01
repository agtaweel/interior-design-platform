<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterClientRequest;
use App\Models\ClientUser;
use App\Services\Auth\ClientPasswordResetService;
use App\Services\Auth\ClientSignupService;
use App\Services\Auth\PasswordResetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * BRD v4 "Client Marketplace" — mirrors AuthController's shape exactly for `ClientUser` instead
 * of `User`. No `me()`-style organizations list (a client belongs to no organization); no
 * `is_platform_owner`-style flag in the payload either.
 */
class ClientAuthController extends Controller
{
    public function __construct(
        private readonly ClientPasswordResetService $passwordResetService,
        private readonly ClientSignupService $signupService,
    ) {}

    public function register(RegisterClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $clientUser = $this->signupService->signup(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            phone: $data['phone'] ?? null,
        );

        $token = $clientUser->createToken('client-api')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'client' => $this->clientPayload($clientUser),
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $clientUser = ClientUser::where('email', $data['email'])->first();

        if (! $clientUser || ! Hash::check($data['password'], $clientUser->password)) {
            return $this->unauthenticated('Invalid email or password.');
        }

        if ($clientUser->status !== 'active') {
            return $this->unauthenticated('This account is not active.');
        }

        $token = $clientUser->createToken('client-api')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'client' => $this->clientPayload($clientUser),
            ],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();

        return response()->json(['data' => ['client' => $this->clientPayload($clientUser)]]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $this->passwordResetService->sendResetLink($data['email']);

        return response()->json([
            'data' => ['message' => 'If an account exists for that email, a reset link has been sent.'],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $this->passwordResetService->reset($data['email'], $data['token'], $data['password']);
        } catch (PasswordResetException) {
            return response()->json([
                'error' => [
                    'code' => 'password_reset_token_invalid',
                    'message' => 'This password reset link is invalid or has expired. Request a new one.',
                    'details' => (object) [],
                ],
            ], 422);
        }

        return response()->json(['data' => ['message' => 'Your password has been reset.']]);
    }

    private function clientPayload(ClientUser $clientUser): array
    {
        return [
            'id' => $clientUser->id,
            'name' => $clientUser->name,
            'email' => $clientUser->email,
            'phone' => $clientUser->phone,
            'status' => $clientUser->status,
        ];
    }

    private function unauthenticated(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => $message,
                'details' => (object) [],
            ],
        ], 401);
    }
}

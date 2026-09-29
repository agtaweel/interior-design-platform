<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\Auth\OrganizationSignupService;
use App\Services\Auth\PasswordResetException;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly OrganizationSignupService $signupService,
    ) {}

    /**
     * POST /auth/register — Platform Readiness Review finding #01. Creates a brand-new
     * organization + owner user and logs them straight in (same token-issuing shape as login()),
     * since a self-serve signup has no separate "invited, set your password" step to wait on.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->signupService->signup(
            organizationName: $data['organization_name'],
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
        );

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }
    /**
     * POST /auth/login — authenticate by email + password, issue a Sanctum personal access
     * token. Deliberately does NOT use ValidationException for bad credentials (that would map
     * to a 422 in our envelope) — wrong email/password is an authentication failure, so it
     * returns the same 401 envelope as an expired/missing token, without revealing whether the
     * email or the password was the problem.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->unauthenticated('Invalid email or password.');
        }

        if ($user->status !== 'active') {
            return $this->unauthenticated('This account is not active.');
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
            ],
        ], 200);
    }

    /**
     * POST /auth/logout — revoke only the token used to authenticate the current request
     * (not all of the user's tokens, so logging out on one device doesn't affect others).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    /**
     * GET /me — current user plus every organization they belong to (id, name, role), so the
     * frontend can build an org switcher without a separate endpoint. Intentionally NOT gated
     * by the `tenant` middleware: this endpoint is inherently cross-organization.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organizations = $user->organizationMemberships()
            ->with(['organization:id,name,currency', 'role:id,name'])
            ->get()
            ->map(fn ($membership) => [
                'organization' => [
                    'id' => $membership->organization->id,
                    'name' => $membership->organization->name,
                    // Platform Readiness Review finding #06: the frontend money formatter
                    // hardcoded "EGP" regardless of the organization's own setting. Exposed here
                    // (rather than requiring MANAGE_ORGANIZATION to fetch the full profile via
                    // getOrganizationProfile()'s no-op-PATCH workaround) so every member, not
                    // just admins, can render amounts in the org's real currency.
                    'currency' => $membership->organization->currency,
                ],
                'role' => $membership->role ? [
                    'id' => $membership->role->id,
                    'name' => $membership->role->name,
                ] : null,
                'status' => $membership->status,
            ])
            ->values();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'organizations' => $organizations,
            ],
        ]);
    }

    /**
     * POST /auth/forgot-password — body: {email}. Platform Readiness Review finding #04.
     * Always returns the SAME generic 200 regardless of whether the email belongs to a real
     * account (PasswordResetService::sendResetLink() is itself a silent no-op for an unknown
     * email) — a public, unauthenticated endpoint must never let a caller enumerate which
     * emails have accounts on the platform.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $this->passwordResetService->sendResetLink($data['email']);

        return response()->json([
            'data' => ['message' => 'If an account exists for that email, a reset link has been sent.'],
        ]);
    }

    /**
     * POST /auth/reset-password — body: {email, token, password, password_confirmation}.
     * 'invalid'/'expired' token reasons are both rendered as the same 422 message — see
     * PasswordResetException's docblock for why they're deliberately not distinguished to the
     * caller.
     */
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

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            // BRD v3 §5/§20 "Platform Owner" — the frontend uses this to route a platform owner
            // to /platform instead of (or in addition to) the normal org-scoped app shell.
            'is_platform_owner' => $user->is_platform_owner,
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

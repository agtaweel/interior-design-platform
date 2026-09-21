<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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
            ->with(['organization:id,name', 'role:id,name'])
            ->get()
            ->map(fn ($membership) => [
                'organization' => [
                    'id' => $membership->organization->id,
                    'name' => $membership->organization->name,
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

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
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

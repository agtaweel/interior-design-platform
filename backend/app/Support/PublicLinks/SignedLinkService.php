<?php

namespace App\Support\PublicLinks;

use App\Models\SignedLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Reusable primitive for short-lived, revocable, single-purpose public links — the mechanism
 * later sprints will use for endpoints like GET/POST /public/proposals/{token} and
 * /public/change-orders/{token}, per the locked product decision (secure link + OTP, no
 * e-signature vendor) and the NFR that public links must never leak internal IDs.
 *
 * Usage (for the agent building the actual public endpoint later):
 *
 *   $token = $signedLinkService->issue(
 *       purpose: 'proposal_approval',
 *       payload: ['proposal_version_id' => $version->id, 'organization_id' => $org->id],
 *       expiresAt: now()->addDays(14),
 *       createdBy: $request->user()->id,
 *   );
 *   // -> share https://app/.../public/proposals/{$token} with the client (WhatsApp, manually)
 *
 *   // In the public controller:
 *   $payload = $signedLinkService->verify($token, 'proposal_approval'); // throws SignedLinkException
 *   $signedLinkService->markUsed($token);
 *
 * The raw token is a 64-char cryptographically random string. Only its SHA-256 hash is
 * persisted (see the signed_links migration) — a database dump does not yield usable links.
 * If the locked OTP-gating decision applies to a given link type, that's a second, independent
 * check the calling controller performs after verify() succeeds; this service only answers
 * "is this token currently valid, and what does it point to".
 */
class SignedLinkService
{
    private const TOKEN_LENGTH = 64;

    public function issue(
        string $purpose,
        array $payload,
        CarbonInterface $expiresAt,
        ?int $createdBy = null,
    ): string {
        $token = Str::random(self::TOKEN_LENGTH);

        SignedLink::create([
            'purpose' => $purpose,
            'token_hash' => $this->hash($token),
            'payload_json' => $payload,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed> the payload the token was issued with
     *
     * @throws SignedLinkException if the token is unknown, expired, revoked, or was issued for
     *                             a different purpose than requested
     */
    public function verify(string $token, string $purpose): array
    {
        $link = SignedLink::query()
            ->where('token_hash', $this->hash($token))
            ->where('purpose', $purpose)
            ->first();

        if (! $link) {
            throw new SignedLinkException('invalid');
        }

        if ($link->isRevoked()) {
            throw new SignedLinkException('revoked');
        }

        if ($link->isExpired()) {
            throw new SignedLinkException('expired');
        }

        return $link->payload_json ?? [];
    }

    public function markUsed(string $token): void
    {
        SignedLink::query()
            ->where('token_hash', $this->hash($token))
            ->update([
                'last_used_at' => now(),
                'use_count' => \Illuminate\Support\Facades\DB::raw('use_count + 1'),
            ]);
    }

    public function revoke(string $token): void
    {
        SignedLink::query()
            ->where('token_hash', $this->hash($token))
            ->update(['revoked_at' => now()]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

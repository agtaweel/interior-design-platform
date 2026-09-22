<?php

namespace App\Services\Proposals;

use App\Models\OtpChallenge;
use App\Models\SignedLink;
use Illuminate\Support\Facades\Hash;

/**
 * Generic OTP-challenge mechanism (PROJECT_CONTEXT.md Sprint 4 "OTP + idempotency mechanism") —
 * deliberately not proposal-specific so Sprint 7's change-order public approval can reuse it
 * unchanged against its own signed_links row.
 *
 * ## Hashing choice: Hash::make()/Hash::check() (bcrypt), not a fast hash like sha256
 *
 * This is a genuine judgment call (PROJECT_CONTEXT.md explicitly leaves it open). A 6-digit
 * numeric OTP only has 1,000,000 possible values, so bcrypt's slowness doesn't meaningfully
 * change the offline brute-force economics the way it does for a real password — an attacker
 * who got the code_hash out of a DB dump and doesn't need to go through the online rate-limited
 * verify() path could still brute-force a fast hash quickly, but bcrypt only buys roughly a
 * constant-factor slowdown here too (small keyspace either way). We use bcrypt anyway for two
 * reasons: (1) consistency — this codebase already has Laravel's Hash facade wired up and used
 * everywhere else a secret is checked (AuthController's password check), so following the same
 * pattern here means no second hashing convention to explain; (2) call volume is inherently low
 * (at most a handful of verify() calls per sent proposal, gated further by the `public-links`
 * rate limiter and the 5-attempt lockout below), so bcrypt's cost is negligible in practice.
 * The primary defense against brute force is online: the attempt cap + expiry, not the hash
 * algorithm — sha256(token) is used for signed_links' token_hash instead precisely because
 * THOSE tokens are high-entropy (64 random chars) and looked up by exact match on every public
 * request, where bcrypt's cost would actually matter and buys nothing against a 256-bit
 * keyspace.
 *
 * ## Expiry: 72 hours
 *
 * PROJECT_CONTEXT.md suggests "e.g. 72 hours, your call". Chosen to comfortably span a
 * client's decision window (a proposal isn't usually approved within minutes) while still
 * being materially shorter than the signed link itself (see SignedLinkService caller in
 * ProposalSendService — the link is issued with a much longer expiry so the client can still
 * VIEW a sent proposal well after the OTP has lapsed; there is no "resend OTP" endpoint this
 * sprint, so once expired, that proposal version can no longer be approved — a known
 * limitation, documented on ProposalSendService).
 *
 * ## Attempt cap: 5
 */
class OtpChallengeService
{
    private const CODE_LENGTH = 6;

    public const EXPIRY_HOURS = 72;

    public const MAX_ATTEMPTS = 5;

    /**
     * @return array{challenge: OtpChallenge, code: string} the raw code is returned ONLY here,
     *                                                      to the caller of send() — never
     *                                                      persisted anywhere but as a hash.
     */
    public function issue(SignedLink $link): array
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $challenge = OtpChallenge::create([
            'signed_link_id' => $link->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addHours(self::EXPIRY_HOURS),
            'attempts' => 0,
        ]);

        return ['challenge' => $challenge, 'code' => $code];
    }

    /**
     * Order of checks is deliberate and matches PROJECT_CONTEXT.md's literal sequence
     * ("validate OTP ... then check current status"): lockout/expiry/hash-mismatch are all
     * OTP-intrinsic checks that happen BEFORE the caller looks at proposal_versions.status at
     * all — so e.g. a wrong code against an already-approved proposal still reports OTP_INVALID
     * (and still increments attempts) rather than short-circuiting to "already approved". Only
     * once the OTP itself checks out does the caller decide what the current state means.
     *
     * Does NOT treat `verified_at` as single-use/consuming: it's a timestamp marker of the
     * first successful verification (audit trail), not a gate against re-verifying the same
     * correct code again. This is intentional — see PublicProposalController::approve()'s
     * docblock for why a second approve() call with the same correct OTP must still reach the
     * "already approved" 409 rather than being rejected earlier as "OTP already used".
     *
     * @throws OtpVerificationException
     */
    public function verify(OtpChallenge $challenge, string $code): void
    {
        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            throw new OtpVerificationException('locked');
        }

        if ($challenge->isExpired()) {
            throw new OtpVerificationException('expired');
        }

        // Hash::check() throws (rather than returning false) if code_hash isn't a recognized
        // hash format. That should never happen since issue() always writes via Hash::make(),
        // but this is a public, unauthenticated endpoint — a corrupted row must fail closed
        // (treated as a wrong code) rather than surface a 500 to an anonymous caller.
        try {
            $matches = Hash::check($code, $challenge->code_hash);
        } catch (\RuntimeException) {
            $matches = false;
        }

        if (! $matches) {
            $challenge->increment('attempts');

            throw new OtpVerificationException('invalid', max(0, self::MAX_ATTEMPTS - $challenge->attempts));
        }
    }
}

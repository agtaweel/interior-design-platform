<?php

namespace Database\Factories;

use App\Models\OtpChallenge;
use App\Models\SignedLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtpChallenge>
 */
class OtpChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'signed_link_id' => SignedLink::factory(),
            // "123456" hashed the same way the real OTP-generation code will (a plain SHA-256
            // of the digit string) — tests that need to verify against a known code can hash
            // the same literal rather than reverse-engineering this factory's random value.
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(15),
            'verified_at' => null,
            'attempts' => 0,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Convenience state for tests exercising the "lock out after e.g. 5 failed attempts" rule
     * from PROJECT_CONTEXT.md's Sprint 4 scope.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attempts' => 5,
        ]);
    }
}

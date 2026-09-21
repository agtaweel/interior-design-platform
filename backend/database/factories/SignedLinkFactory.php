<?php

namespace Database\Factories;

use App\Models\SignedLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignedLink>
 */
class SignedLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purpose' => 'test_purpose',
            'token_hash' => hash('sha256', Str::random(64)),
            'payload_json' => [],
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'use_count' => 0,
            'created_by' => null,
        ];
    }
}

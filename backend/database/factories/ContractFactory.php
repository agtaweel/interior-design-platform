<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Project;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * Default state: a freshly-converted contract, the shape a contract has immediately at
     * creation (creation IS signing, per PROJECT_CONTEXT.md's Sprint 5 decision — signed_at
     * is always set, there's no unsigned/draft state).
     *
     * contract_no uses the factory sequence (not fake()->unique()) so parallel/repeated test
     * runs never collide, matching the "CTR-00001" style of the real auto-generation pattern
     * (projects.code's "PRJ-00001") that backend-api-engineer will implement.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'proposal_version_id' => ProposalVersion::factory()->approved(),
            'contract_no' => 'CTR-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => 'active',
            'contract_value' => fake()->randomFloat(2, 60000, 240000),
            'signed_at' => now(),
            'start_date' => fake()->optional()->dateTimeBetween('now', '+1 month'),
            'end_date' => fake()->optional()->dateTimeBetween('+2 months', '+8 months'),
            'terms_json' => [
                'payment_plan' => fake()->sentence(),
                'exclusions' => fake()->sentence(),
            ],
        ];
    }
}

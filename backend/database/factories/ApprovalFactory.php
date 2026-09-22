<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\Project;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    /**
     * Default state: a client approval against a proposal version, the most common case for
     * Sprint 4's public approve endpoint.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Resolve organization_id from the (possibly newly created) project so the two
            // never disagree — same rationale as ProjectFactory resolving from Client.
            'project_id' => Project::factory(),
            'organization_id' => function (array $attributes) {
                return Project::find($attributes['project_id'])?->organization_id;
            },
            'entity_type' => ProposalVersion::ENTITY_TYPE,
            'entity_id' => ProposalVersion::factory(),
            'approver_type' => 'client',
            'user_id' => null,
            'status' => 'approved',
            'comment' => null,
            'approved_at' => now(),
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * An internal-staff approval instead of a client one — user_id populated, no ip_address
     * requirement (internal actions aren't necessarily captured the same way public ones are).
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approver_type' => 'internal',
        ]);
    }

    /**
     * "Changes requested" instead of approved — the other action the public proposal portal
     * supports, per PROJECT_CONTEXT.md's Sprint 4 scope. No OTP/approved_at for this one.
     */
    public function changesRequested(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'changes_requested',
            'approved_at' => null,
            'comment' => fake()->sentence(),
        ]);
    }
}

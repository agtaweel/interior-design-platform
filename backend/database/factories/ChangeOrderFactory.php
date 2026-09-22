<?php

namespace Database\Factories;

use App\Models\ChangeOrder;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeOrder>
 */
class ChangeOrderFactory extends Factory
{
    /**
     * Default state: a freshly-created draft, the shape a change order has immediately after
     * creation before any send/approve/apply transition.
     *
     * `number` uses fake()->unique() (not a Sequence class) so parallel/repeated test runs
     * never collide, matching ContractFactory's exact "CTR-00001" style for its `contract_no`
     * sequence — same pattern here for "CO-00001".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'number' => 'CO-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'reason' => fake()->randomElement([
                'Client requested an upgrade to a higher-grade porcelain tile finish.',
                'Additional electrical points needed after the site survey revealed extra circuits.',
                'Removing the built-in wardrobe line item at the client\'s request to reduce cost.',
                'Kitchen layout revision requires swapping cabinet units for a different configuration.',
                'Supervision fee adjustment following an extended project timeline.',
            ]),
            'price_delta' => null,
            'timeline_delta_days' => null,
            'requested_by' => null,
            'approved_at' => null,
            'applied_at' => null,
        ];
    }

    /**
     * A change order that has been sent to the client: locks the draft and records sent_at,
     * matching proposal_versions.sent_at exactly (added in a follow-up migration after this
     * column was initially omitted from the Sprint 7 schema despite the lifecycle spec calling
     * for it).
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * A change order the client has approved. Builds on sent() since approval can only ever
     * follow sending, same convention as ProposalVersionFactory::approved().
     */
    public function approved(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    /**
     * A change order staff has applied to the BOQ/contract — the terminal successful state.
     */
    public function applied(): static
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'status' => 'applied',
            'applied_at' => now(),
        ]);
    }

    /**
     * A change order the client rejected — terminal, no further transitions.
     */
    public function rejected(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => 'rejected',
        ]);
    }
}

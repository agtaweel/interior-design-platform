<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalVersion>
 */
class ProposalVersionFactory extends Factory
{
    /**
     * Default state: a fully-priced draft (version 1), the shape a version has immediately
     * after creation before any send/approve transition. Use the named states below for the
     * other statuses, or override fields inline for anything else a test needs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version_no' => 1,
            'status' => 'draft',
            'subtotal' => fake()->randomFloat(2, 50000, 200000),
            'markup_total' => fake()->randomFloat(2, 5000, 30000),
            'fees_total' => fake()->randomFloat(2, 1000, 10000),
            'discount_total' => 0,
            'grand_total' => fake()->randomFloat(2, 60000, 240000),
            'content_json' => [
                'cover_note' => fake()->sentence(),
                'scope' => fake()->paragraph(),
                'exclusions' => [],
                'timeline' => fake()->sentence(),
                'terms' => fake()->paragraph(),
                'payment_plan' => fake()->sentence(),
            ],
            'snapshot_json' => null,
            'created_by' => null,
            'sent_at' => null,
            'approved_at' => null,
        ];
    }

    /**
     * A version that has been sent to the client: snapshot_json finalized, sent_at populated.
     * Per PROJECT_CONTEXT.md's immutability rule, a sent version's content/items/totals are
     * frozen — this state captures what that looks like for tests that don't need to exercise
     * the send transition itself.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'sent',
            'sent_at' => now(),
            'snapshot_json' => [
                'content_json' => $attributes['content_json'] ?? [],
                'totals' => [
                    'subtotal' => $attributes['subtotal'] ?? null,
                    'markup_total' => $attributes['markup_total'] ?? null,
                    'fees_total' => $attributes['fees_total'] ?? null,
                    'discount_total' => $attributes['discount_total'] ?? null,
                    'grand_total' => $attributes['grand_total'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * A version the client has approved. Builds on sent() since approval can only ever follow
     * sending (PROJECT_CONTEXT.md: the immutability boundary is already at `sent`, approval
     * doesn't introduce a new one).
     */
    public function approved(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }
}

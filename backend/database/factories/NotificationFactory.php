<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Realistic examples for the six event types PROJECT_CONTEXT.md's Sprint 8 "Notifications"
     * section names as the wiring points (proposal approved/changes-requested, contract
     * created, payment received, change order approved/rejected). `type` is the short event
     * identifier used by the frontend/backend to key off of; `payload_json` carries whatever a
     * bell-dropdown row needs to render/link without a follow-up request (a project id/name, the
     * relevant entity id, and a human summary), per that section's own description.
     *
     * organization_id and user_id resolve independently (Organization::factory() /
     * User::factory()), same convention as OrganizationMemberFactory — there's no FK tying them
     * together, and a notification has no natural parent chain to derive organization_id from
     * (unlike PaymentFactory's payment_schedule->contract->project chain), so tests that care
     * about a specific user/org combination pass them explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $examples = [
            [
                'type' => 'proposal_approved',
                'summary' => fn () => sprintf(
                    '%s approved Proposal v%d for %s',
                    fake()->name(),
                    fake()->numberBetween(1, 4),
                    fake()->streetName().' Apartment'
                ),
            ],
            [
                'type' => 'proposal_changes_requested',
                'summary' => fn () => sprintf(
                    '%s requested changes on Proposal v%d for %s',
                    fake()->name(),
                    fake()->numberBetween(1, 4),
                    fake()->streetName().' Apartment'
                ),
            ],
            [
                'type' => 'contract_created',
                'summary' => fn () => sprintf(
                    'Contract %s created for %s',
                    'CTR-'.str_pad((string) fake()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
                    fake()->streetName().' Villa'
                ),
            ],
            [
                'type' => 'payment_received',
                'summary' => fn () => sprintf(
                    'Payment of EGP %s received for %s',
                    number_format(fake()->randomFloat(2, 5000, 150000), 2),
                    fake()->streetName().' Apartment'
                ),
            ],
            [
                'type' => 'change_order_approved',
                'summary' => fn () => sprintf(
                    'Change Order %s approved for %s',
                    'CO-'.str_pad((string) fake()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
                    fake()->streetName().' Apartment'
                ),
            ],
            [
                'type' => 'change_order_rejected',
                'summary' => fn () => sprintf(
                    'Change Order %s rejected for %s',
                    'CO-'.str_pad((string) fake()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
                    fake()->streetName().' Apartment'
                ),
            ],
        ];

        $example = fake()->randomElement($examples);

        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'channel' => 'in_app',
            'type' => $example['type'],
            'payload_json' => [
                'project_id' => fake()->numberBetween(1, 1000),
                'project_name' => fake()->streetName().' '.fake()->randomElement(['Apartment', 'Villa', 'Office']),
                'entity_id' => fake()->numberBetween(1, 1000),
                'summary' => $example['summary'](),
            ],
            'sent_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'read_at' => null,
        ];
    }

    /**
     * A notification the user has already read — sets read_at to some point after sent_at.
     */
    public function read(): static
    {
        return $this->state(function (array $attributes): array {
            $sentAt = $attributes['sent_at'] ?? now();

            return [
                'read_at' => fake()->dateTimeBetween($sentAt, 'now'),
            ];
        });
    }
}

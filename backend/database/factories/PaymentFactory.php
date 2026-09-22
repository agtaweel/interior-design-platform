<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Default state: a full payment recorded "just now" against a freshly-created payment
     * schedule/contract/project chain. organization_id/project_id are resolved from the (newly
     * created) payment_schedule's contract->project so all three never disagree — same pattern
     * ProjectFactory uses to resolve organization_id from its client_id (factories run outside
     * the request lifecycle/TenantContext, so BelongsToOrganization's saving-listener auto-fill
     * doesn't apply; the factory must set organization_id explicitly).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_schedule_id' => PaymentSchedule::factory(),
            'organization_id' => function (array $attributes) {
                return PaymentSchedule::find($attributes['payment_schedule_id'])
                    ?->contract?->project?->organization_id;
            },
            'project_id' => function (array $attributes) {
                return PaymentSchedule::find($attributes['payment_schedule_id'])
                    ?->contract?->project_id;
            },
            'amount' => fake()->randomFloat(2, 5000, 100000),
            'payment_method' => fake()->randomElement(['bank_transfer', 'cash', 'cheque']),
            'paid_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'reference' => fake()->optional()->bothify('REF-####??'),
            'receipt_url' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}

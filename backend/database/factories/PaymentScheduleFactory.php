<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSchedule>
 */
class PaymentScheduleFactory extends Factory
{
    /**
     * Default state: a single pending installment with a realistic percentage/amount pairing.
     * Callers building a full 3-installment plan against a shared contract should override
     * contract_id/sequence_no/percentage themselves (see e.g. PaymentScheduleTest) rather than
     * relying on this factory to sequence multiple rows against one contract, since
     * `Contract::factory()` here creates a fresh, unrelated contract per call.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $percentage = fake()->randomElement([10, 20, 25, 30, 50]);
        $contractValue = fake()->randomFloat(2, 60000, 240000);
        $amount = bcmul((string) $contractValue, bcdiv((string) $percentage, '100', 4), 2);

        return [
            'contract_id' => Contract::factory(),
            'name' => fake()->randomElement(['Deposit', 'Milestone Payment', 'Final Payment']),
            'sequence_no' => fake()->numberBetween(1, 3),
            'due_date' => fake()->dateTimeBetween('now', '+6 months'),
            'percentage' => $percentage,
            'amount' => $amount,
            'status' => 'pending',
        ];
    }

    /**
     * A schedule already fully paid — status flipped as if cumulative payments reached amount.
     */
    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid']);
    }

    /**
     * A schedule whose due_date is in the past and still pending — i.e. computed-overdue per
     * PaymentSchedule::isOverdue().
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'due_date' => fake()->dateTimeBetween('-3 months', '-1 day'),
        ]);
    }
}

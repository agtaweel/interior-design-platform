<?php

namespace Database\Factories;

use App\Models\BoqUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqUnit>
 */
class BoqUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('unit-????'),
            'name_en' => fake()->randomElement(['Square Meter', 'Linear Meter', 'Piece', 'Point', 'Set']),
            'name_ar' => fake()->randomElement(['متر مربع', 'متر طولي', 'قطعة', 'نقطة', 'طقم']),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}

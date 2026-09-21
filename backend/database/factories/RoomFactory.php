<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement([
                'Living Room', 'Master Bedroom', 'Kitchen', 'Bathroom', 'Dining Room',
                'Guest Bedroom', 'Reception', 'Office', 'Balcony', 'Hallway',
            ]),
            'area_m2' => fake()->randomFloat(2, 8, 60),
            'sort_order' => 0,
        ];
    }
}

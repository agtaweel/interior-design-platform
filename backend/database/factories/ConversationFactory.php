<?php

namespace Database\Factories;

use App\Models\ClientUser;
use App\Models\Conversation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'client_user_id' => ClientUser::factory(),
            'project_id' => null,
            'status' => 'open',
        ];
    }
}

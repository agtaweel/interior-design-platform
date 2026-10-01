<?php

namespace Tests\Feature\Marketplace;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * BRD v4 "Client Marketplace" Phase C — GET /client/projects(/{project}...). Mirrors
 * PublicClientPortalController's own test coverage shape, swapping the token for an
 * authenticated ClientUser; the one thing worth re-proving here (not already covered by the
 * public-portal tests) is that ClientOwnershipResolver, not a bare find(), is what gates every
 * one of these routes.
 */
class ClientProjectTest extends TestCase
{
    use RefreshDatabase;

    /** Auth::forgetGuards() — see laravel-sanctum-test-user-switch-quirk memory. */
    private function clientAuthHeader(ClientUser $clientUser): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$clientUser->createToken('t')->plainTextToken];
    }

    private function projectFor(ClientUser $clientUser): Project
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'client_user_id' => $clientUser->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    public function test_client_can_list_their_own_projects_only(): void
    {
        $clientUser = ClientUser::factory()->create();
        $ownProject = $this->projectFor($clientUser);

        $otherClient = ClientUser::factory()->create();
        $this->projectFor($otherClient);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->getJson('/api/v1/client/projects')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownProject->id);
    }

    public function test_client_can_view_their_own_project_overview_without_cost_fields(): void
    {
        $clientUser = ClientUser::factory()->create();
        $project = $this->projectFor($clientUser);

        $response = $this->withHeaders($this->clientAuthHeader($clientUser))
            ->getJson("/api/v1/client/projects/{$project->id}");

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.financials.quoted_cost')
            ->assertJsonMissingPath('data.financials.margin_percent')
            ->assertJsonStructure(['data' => ['financials' => ['value', 'collected', 'outstanding']]]);
    }

    public function test_client_cannot_view_another_clients_project_or_any_subresource(): void
    {
        $clientUser = ClientUser::factory()->create();
        $otherClient = ClientUser::factory()->create();
        $project = $this->projectFor($otherClient);
        $header = $this->clientAuthHeader($clientUser);

        $this->withHeaders($header)->getJson("/api/v1/client/projects/{$project->id}")->assertStatus(404);
        $this->withHeaders($header)->getJson("/api/v1/client/projects/{$project->id}/contract")->assertStatus(404);
        $this->withHeaders($header)->getJson("/api/v1/client/projects/{$project->id}/payments")->assertStatus(404);
        $this->withHeaders($header)->getJson("/api/v1/client/projects/{$project->id}/change-orders")->assertStatus(404);
        $this->withHeaders($header)->getJson("/api/v1/client/projects/{$project->id}/media")->assertStatus(404);
    }

    public function test_client_can_view_their_own_projects_contract_and_payments(): void
    {
        $clientUser = ClientUser::factory()->create();
        $project = $this->projectFor($clientUser);
        $contract = Contract::factory()->create(['project_id' => $project->id]);
        $schedule = PaymentSchedule::factory()->create(['contract_id' => $contract->id]);
        Payment::factory()->create(['payment_schedule_id' => $schedule->id]);

        $header = $this->clientAuthHeader($clientUser);

        $this->withHeaders($header)
            ->getJson("/api/v1/client/projects/{$project->id}/contract")
            ->assertStatus(200)
            ->assertJsonPath('data.contract_no', $contract->contract_no);

        $this->withHeaders($header)
            ->getJson("/api/v1/client/projects/{$project->id}/payments")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_client_can_view_their_own_projects_change_orders(): void
    {
        $clientUser = ClientUser::factory()->create();
        $project = $this->projectFor($clientUser);
        ChangeOrder::factory()->applied()->create(['project_id' => $project->id, 'price_delta' => '5000.00']);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->getJson("/api/v1/client/projects/{$project->id}/change-orders")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.boq_item_id');
    }
}

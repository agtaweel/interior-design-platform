<?php

namespace Tests\Feature\Projects;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Handover;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\Snag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /projects/{id}/journey — the stage + "next step" behind the project screen's stage grouping
 * (docs/SIMPLIFICATION_REVIEW.md P2.3). Walks one project through its whole life and checks the
 * single next action at each point.
 */
class ProjectJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $organization, array $permissions = []): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    private function journey(User $user, Project $project): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->getJson("/api/v1/projects/{$project->id}/journey")
            ->assertStatus(200)
            ->json('data');
    }

    private function assertStep(User $user, Project $project, string $stage, string $key, string $tab): void
    {
        $data = $this->journey($user, $project);
        $this->assertSame(
            [$stage, $key, $tab],
            [$data['stage'], $data['next_step']['key'], $data['next_step']['tab']],
            'facts: '.json_encode($data['facts']),
        );
    }

    public function test_next_step_follows_the_project_from_empty_to_handed_over(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->member($organization);

        $this->assertStep($user, $project, 'quote', 'add_items', 'boq');

        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
        $this->assertStep($user, $project, 'quote', 'create_proposal', 'proposal');

        $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'draft']);
        $this->assertStep($user, $project, 'quote', 'send_proposal', 'proposal');

        $proposal->forceFill(['status' => 'sent'])->save();
        $this->assertStep($user, $project, 'quote', 'await_client', 'proposal');

        $proposal->forceFill(['status' => 'changes_requested'])->save();
        $this->assertStep($user, $project, 'quote', 'revise_proposal', 'proposal');

        $proposal->forceFill(['status' => 'approved'])->save();
        $this->assertStep($user, $project, 'agreement', 'convert_to_contract', 'contract');

        $contract = Contract::factory()->create(['project_id' => $project->id, 'proposal_version_id' => $proposal->id]);
        $this->assertStep($user, $project, 'agreement', 'set_installments', 'payments');

        PaymentSchedule::factory()->create(['contract_id' => $contract->id]);
        $this->assertStep($user, $project, 'work', 'track_work', 'execution');

        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
        $this->assertStep($user, $project, 'work', 'apply_change_order', 'change-orders');
        $changeOrder->forceFill(['status' => 'applied', 'applied_at' => now()])->save();

        $snag = Snag::factory()->create(['project_id' => $project->id, 'is_mandatory' => true]);
        $this->assertStep($user, $project, 'finish', 'close_snags', 'snagging');

        $snag->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->assertStep($user, $project, 'finish', 'record_handover', 'handover');

        Handover::factory()->create(['project_id' => $project->id]);
        $this->assertStep($user, $project, 'done', 'handed_over', 'handover');
    }

    public function test_payload_carries_no_money_and_is_open_to_members_without_financial_permission(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
        Contract::factory()->create(['project_id' => $project->id, 'proposal_version_id' => $proposal->id]);

        $data = $this->journey($this->member($organization, []), $project);

        $flat = json_encode($data);
        foreach (['value', 'total', 'cost', 'amount', 'margin', 'profit', 'price'] as $moneyWord) {
            $this->assertStringNotContainsString($moneyWord, strtolower($flat), "journey payload mentions '{$moneyWord}'");
        }
    }

    public function test_another_organizations_project_is_not_found(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $projectB = $this->projectIn($orgB);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->member($orgA)->createToken('t')->plainTextToken])
            ->getJson("/api/v1/projects/{$projectB->id}/journey")
            ->assertStatus(404);
    }
}

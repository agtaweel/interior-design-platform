<?php

namespace Tests\Feature\Projects;

use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Database\Seeders\BoqMasterCatalogSeeder;
use Database\Seeders\BoqTemplateSeeder;
use Database\Seeders\BoqUnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/SIMPLIFICATION_REVIEW.md P4.3 — the one-click sample project (SampleProjectService).
 */
class SampleProjectTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $organization, array $permissions): User
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

    private function as(User $user, Organization $organization): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'X-Organization-Id' => (string) $organization->id,
        ]);
    }

    private function owner(Organization $organization): User
    {
        return $this->member($organization, [
            Permissions::MANAGE_PROJECTS => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
    }

    public function test_creates_a_priced_labelled_sample_with_a_draft_proposal_once(): void
    {
        $this->seed([BoqUnitSeeder::class, BoqMasterCatalogSeeder::class, BoqTemplateSeeder::class]);
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);

        $id = $this->as($owner, $organization)->postJson('/api/v1/sample-project', ['locale' => 'ar'])
            ->assertStatus(201)->json('data.project_id');

        $project = Project::find($id);
        $this->assertTrue($project->is_sample);
        $this->assertSame('SAMPLE', $project->code);
        $this->assertStringContainsString('نموذج', $project->name);
        $this->assertTrue(Client::find($project->client_id)->is_sample);
        $this->assertGreaterThan(50, BoqItem::where('project_id', $id)->count());
        $this->assertGreaterThan(0, (float) $project->grand_total);
        $this->assertGreaterThan(0, (float) $project->markup_total);
        $this->assertSame('draft', ProposalVersion::where('project_id', $id)->value('status'));

        // Idempotent — one sample per company.
        $this->as($owner, $organization)->postJson('/api/v1/sample-project')->assertStatus(201)->assertJsonPath('data.project_id', $id);
        $this->as($owner, $organization)->getJson('/api/v1/sample-project')->assertJsonPath('data.project_id', $id);
        $this->assertSame(1, Project::where('is_sample', true)->count());
    }

    public function test_the_sample_stays_out_of_money_figures_and_the_real_client_count(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $this->as($owner, $organization)->postJson('/api/v1/sample-project')->assertStatus(201);
        $sample = Project::where('is_sample', true)->first();
        $this->payFor($sample, '5000.00');

        $this->as($owner, $organization)->getJson('/api/v1/reports/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.project_count', 0)
            ->assertJsonPath('data.total_revenue', '0.00');
        $this->as($owner, $organization)->getJson('/api/v1/reports/projects')->assertJsonCount(0, 'data');
        $this->as($owner, $organization)->getJson('/api/v1/clients?exclude_sample=1')->assertJsonPath('meta.total', 0);
        $this->as($owner, $organization)->getJson('/api/v1/clients')->assertJsonPath('meta.total', 1);
        $this->as($owner, $organization)->getJson("/api/v1/projects/{$sample->id}")->assertJsonPath('data.is_sample', true);
    }

    public function test_delete_removes_everything_even_after_contract_and_payments_and_nothing_else(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->owner($organization);
        $this->as($owner, $organization)->postJson('/api/v1/sample-project')->assertStatus(201);
        $sample = Project::where('is_sample', true)->first();
        $this->payFor($sample, '1000.00');

        $realClient = Client::factory()->create(['organization_id' => $organization->id]);
        $real = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $realClient->id]);

        $this->as($owner, $organization)->deleteJson('/api/v1/sample-project')->assertStatus(204);

        $this->assertNull(Project::find($sample->id));
        $this->assertNull(Client::find($sample->client_id));
        $this->assertSame(0, Contract::where('project_id', $sample->id)->count());
        $this->assertSame(0, Payment::where('project_id', $sample->id)->count());
        $this->assertNotNull(Project::find($real->id));
        $this->assertNotNull(Client::find($realClient->id));
        $this->as($owner, $organization)->getJson('/api/v1/sample-project')->assertJsonPath('data', null);
    }

    public function test_needs_manage_projects_and_never_touches_another_company(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->as($this->owner($other), $other)->postJson('/api/v1/sample-project')->assertStatus(201);

        $viewer = $this->member($organization, []);
        $this->as($viewer, $organization)->postJson('/api/v1/sample-project')->assertStatus(403);
        $this->as($viewer, $organization)->getJson('/api/v1/sample-project')->assertJsonPath('data', null);

        $this->as($this->owner($organization), $organization)->deleteJson('/api/v1/sample-project')->assertStatus(204);
        $this->assertSame(1, Project::withoutGlobalScopes()->where('organization_id', $other->id)->where('is_sample', true)->count());
    }

    private function payFor(Project $project, string $amount): void
    {
        $proposal = ProposalVersion::where('project_id', $project->id)->first();
        $proposal->forceFill(['status' => 'approved'])->save();
        $contract = Contract::factory()->create(['project_id' => $project->id, 'proposal_version_id' => $proposal->id]);
        $schedule = PaymentSchedule::factory()->create(['contract_id' => $contract->id]);
        Payment::factory()->create(['project_id' => $project->id, 'payment_schedule_id' => $schedule->id, 'amount' => $amount, 'paid_at' => now()]);
    }
}

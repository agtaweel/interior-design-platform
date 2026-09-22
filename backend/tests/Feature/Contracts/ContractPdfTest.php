<?php

namespace Tests\Feature\Contracts;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Contracts\ContractPresenter;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * GET /contracts/{id}/pdf (PROJECT_CONTEXT.md Sprint 5). Contracts carry no BOQ-item-level cost
 * fields at all (contract_value is the only money figure on the model), so "no cost data leaks
 * into the PDF" is trivially true on the schema alone — per the task brief, what's actually
 * worth verifying is that ContractPresenter::present()/resources/views/contracts/pdf.blade.php
 * don't accidentally reach into the project's BOQ (e.g. via a convenience eager-load) and render
 * raw cost data for display. Same verification strategy as ProposalPdfTest: render the Blade
 * view directly with the exact payload the controller feeds it and assert the HTML never
 * contains the seeded cost figures.
 */
class ContractPdfTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions): User
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

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    /** Distinctive, hand-picked cost figures so a leak is unambiguous to detect. */
    private function seedBoqItemWithDistinctiveCosts(Project $project): void
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'material_unit_cost' => 811.11,
            'labor_unit_cost' => 622.22,
            'other_unit_cost' => 433.33,
            'client_unit_price' => 1500,
            'quantity' => 1,
        ]);
    }

    public function test_pdf_endpoint_returns_application_pdf(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
        ]);

        $response = $this->withHeaders($this->authHeader($user))->get("/api/v1/contracts/{$contract->id}/pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_rendered_pdf_html_never_contains_boq_cost_or_margin_figures_or_labels(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItemWithDistinctiveCosts($project);

        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
        ]);

        // Exactly what ContractController::pdf() feeds the Blade view.
        $payload = app(ContractPresenter::class)->present($contract);
        $html = View::make('contracts.pdf', ['data' => $payload])->render();

        foreach (['811.11', '622.22', '433.33'] as $costFigure) {
            $this->assertStringNotContainsString($costFigure, $html, "Contract PDF HTML leaked BOQ cost figure {$costFigure}");
        }
        foreach (['material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'direct_cost', 'source_boq_item_id'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $html);
        }

        // Sanity: the contract_value DOES appear — proves the view is rendering real data.
        $this->assertStringContainsString(number_format((float) $contract->contract_value, 2), $html);
    }

    public function test_contract_presenter_does_not_eager_load_boq_items_at_all(): void
    {
        // Belt-and-braces confirmation at the data layer, not just the rendered HTML: the
        // presenter's payload should never carry a boq-shaped structure for the view to
        // accidentally render in a future edit.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->seedBoqItemWithDistinctiveCosts($project);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
        ]);

        $payload = app(ContractPresenter::class)->present($contract);

        $this->assertArrayNotHasKey('boq', $payload);
        $this->assertArrayNotHasKey('boq_items', $payload);
        $this->assertArrayNotHasKey('items', $payload);
    }
}

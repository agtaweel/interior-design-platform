<?php

namespace Tests\Feature\Proposals;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Proposals\ProposalPresenter;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 4 PDF requirement (Definition of Done: "generated as PDF"):
 * GET /proposals/{id}/pdf (internal) and GET /public/proposals/{token}/pdf (public) must both
 * return application/pdf, and neither may render cost/margin fields.
 *
 * The compiled PDF binary itself isn't practically grep-able for cost figures (dompdf's content
 * streams are typically Flate-compressed), so the cost-exclusion check is done at the
 * Blade-view/data level instead, per the task's own suggested approach: render
 * `proposals.pdf` with the exact payload each controller feeds it and assert the HTML output
 * never contains a cost figure or a subtotal/markup/fees/discount label. This is a faithful
 * proxy for "the PDF doesn't show it" since dompdf renders exactly this HTML with no additional
 * data access of its own.
 *
 * Note: ProposalVersionController::pdf() (internal) feeds the Blade view the FULL, unscrubbed
 * ProposalPresenter::present() payload (including subtotal/markup_total/fees_total/
 * discount_total) — unlike PublicProposalController::pdf(), which scrubs through
 * PublicProposalResource first. This is safe only because the Blade template itself never
 * references those keys (it only ever reads $data['pricing']['grand_total']) — verified below.
 * It is still a latent risk: if `resources/views/proposals/pdf.blade.php` is ever edited to add
 * a "breakdown" section without also scrubbing the internal controller's payload, the internal
 * PDF would start leaking the breakdown (still not cost/margin, since proposal_items never
 * carry those — but the markup/fee/discount breakdown is internal-only data). Flagged as a
 * design note, not a bug: today's template doesn't do this.
 */
class ProposalPdfTest extends TestCase
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

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'material_unit_cost' => 424.24,
            'labor_unit_cost' => 313.13,
            'other_unit_cost' => 202.02,
            'client_unit_price' => 500,
            'quantity' => 1,
        ]);
    }

    private function createAndSendProposal(array $headers, Project $project): array
    {
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token];
    }

    public function test_internal_pdf_endpoint_returns_application_pdf(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $response = $this->withHeaders($headers)->get("/api/v1/proposals/{$id}/pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_public_pdf_endpoint_returns_application_pdf(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $response = $this->get("/api/v1/public/proposals/{$token}/pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_public_pdf_of_an_invalid_token_returns_a_clean_error_not_a_crash(): void
    {
        $response = $this->getJson('/api/v1/public/proposals/nonexistent-token/pdf');

        $response->assertStatus(404)->assertJsonPath('error.code', 'proposal_link_invalid');
    }

    public function test_the_rendered_pdf_html_never_contains_cost_or_margin_figures_or_labels(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project); // material=424.24, labor=313.13, other=202.02

        $id = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        // Give the version a distinctive, hand-picked fee so it's obviously present in the
        // payload passed to the view (proving we're testing the REAL payload, not an empty one).
        $version = ProposalVersion::find($id);
        $version->forceFill(['fees_total' => '777.77', 'markup_total' => '666.66'])->save();

        // Exactly what ProposalVersionController::pdf() (internal) feeds the Blade view: the
        // full, unscrubbed presenter payload.
        $payload = app(ProposalPresenter::class)->present($version->fresh(['items', 'project.client', 'project.property', 'project.organization']));
        $html = View::make('proposals.pdf', ['data' => $payload])->render();

        foreach (['424.24', '313.13', '202.02'] as $costFigure) {
            $this->assertStringNotContainsString($costFigure, $html, "PDF HTML leaked cost figure {$costFigure}");
        }
        // The internal-only breakdown figures we just set must also never be printed, even
        // though they ARE present in the payload object passed to the view.
        $this->assertStringNotContainsString('777.77', $html);
        $this->assertStringNotContainsString('666.66', $html);

        foreach (['material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'source_boq_item_id'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $html);
        }
        foreach (['Markup', 'markup_total', 'Subtotal', 'subtotal', 'fees_total', 'discount_total'] as $forbiddenLabel) {
            $this->assertStringNotContainsString($forbiddenLabel, $html);
        }

        // Sanity: the grand_total DOES appear — proves the view is rendering real data, not
        // just an empty/broken template that trivially passes the assertions above.
        $this->assertStringContainsString(number_format((float) $version->fresh()->grand_total, 2), $html);
    }
}

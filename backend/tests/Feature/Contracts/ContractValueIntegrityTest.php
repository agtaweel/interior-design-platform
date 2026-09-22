<?php

namespace Tests\Feature\Contracts;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PricingRule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 5 immutability boundary: "contract_value ... must equal the source
 * proposal's grand_total exactly, never recalculated or edited." This is also half of the
 * sprint's Definition of Done claim ("An approved proposal can become a contract without
 * losing the approved commercial snapshot").
 *
 * Deliberately uses a project with an ACTIVE pricing_rule on top of the bare BOQ (a 12% Design
 * Fee against the client subtotal, per PricingRuleFactory::designFee()'s documented example) so
 * grand_total is NOT trivially equal to a raw sum of BOQ line items — proving the value that
 * lands on the contract really is the post-pricing-engine figure, not an accidental re-sum of
 * boq_items. All money is compared as fixed 2-decimal strings (bccomp-safe), never as float
 * equality, per the task's explicit instruction and this codebase's general "money is decimal,
 * never float" convention (PROJECT_CONTEXT.md's Stack section).
 *
 * The full real flow is driven end to end over HTTP (BOQ -> pricing rule -> recalculate ->
 * draft proposal -> send -> OTP-approve -> convert to contract) rather than factory-shortcutting
 * any step, since this is precisely the scenario the sprint's Definition of Done is about.
 */
class ContractValueIntegrityTest extends TestCase
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

    /**
     * direct_cost = (100 + 0 + 0) * 10 = 1000.00; client_total = 200 * 10 = 2000.00 — the same
     * hand-verifiable round numbers PricingRecalculationTest uses.
     */
    private function seedKnownBoq(Project $project): void
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'material_unit_cost' => 100,
            'labor_unit_cost' => 0,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ]);
    }

    public function test_contract_value_equals_the_proposals_grand_total_with_pricing_rules_applied_not_the_raw_boq_sum(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);

        $this->seedKnownBoq($project);
        // client_subtotal = 2000.00; +12% Design Fee = 240.00 -> grand_total = 2240.00.
        PricingRule::factory()->designFee()->create(['project_id' => $project->id]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/recalculate")
            ->assertStatus(200);

        $proposalId = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $proposal = ProposalVersion::find($proposalId);
        // Sanity: the proposal's own grand_total is the post-pricing-engine figure, not the raw
        // BOQ client-subtotal sum — proves the fixture is exercising a non-trivial calculation.
        $this->assertSame('2240.00', (string) $proposal->grand_total);
        $this->assertNotSame('2000.00', (string) $proposal->grand_total);

        $send = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$proposalId}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));
        $otp = $send->json('data.otp_code');

        $this->postJson("/api/v1/public/proposals/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        $convert = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposalId}")
            ->assertStatus(201);

        $contract = Contract::first();
        $freshProposal = ProposalVersion::find($proposalId);

        // Exact decimal-string equality — never float comparison — between the frozen contract
        // value and the source proposal's (already-frozen-since-`sent`) grand_total.
        $this->assertSame((string) $freshProposal->grand_total, (string) $contract->contract_value);
        $this->assertSame('2240.00', (string) $contract->contract_value);
        $this->assertSame(0, bccomp((string) $freshProposal->grand_total, (string) $contract->contract_value, 2));

        // And the API response agrees too.
        $this->assertSame('2240.00', (string) $convert->json('data.contract_value'));

        // The contract value is NOT just the raw BOQ client-subtotal (2000.00) — the pricing
        // rule's contribution genuinely made it into the frozen commercial snapshot.
        $this->assertNotSame('2000.00', (string) $contract->contract_value);
    }
}

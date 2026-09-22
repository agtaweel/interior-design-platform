<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ContractService::seedTermsJson() (PROJECT_CONTEXT.md Sprint 5): at conversion time,
 * terms_json is seeded from the source proposal's content_json (terms/exclusions/timeline/
 * payment_plan keys, copied verbatim under the same names), plus two contract-only slots
 * (`warranty_period`/`cancellation_policy`) that have no proposal-side equivalent and must be
 * explicitly present as null (not omitted) so the PATCH-editable shape is discoverable from a
 * single GET. Deliberately does NOT copy cover_note/scope (proposal-presentation content, not
 * contractual terms) — asserted here too, since a bug that copied the whole content_json blob
 * verbatim would otherwise slip past a test that only checked the four terms-adjacent keys.
 */
class ContractTermsSeedingTest extends TestCase
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

    public function test_converting_seeds_terms_json_from_the_proposals_content_json_plus_null_contract_only_slots(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $contentJson = [
            'cover_note' => 'Thank you for choosing us',
            'scope' => 'Full apartment finishing',
            'terms' => 'Payment due within 15 days of each milestone.',
            'exclusions' => 'Furniture and appliances are not included.',
            'timeline' => '16 weeks from contract signing.',
            'payment_plan' => '30% down, 40% at midpoint, 30% at handover.',
        ];

        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'content_json' => $contentJson,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);

        $contract = Contract::first();

        // The four terms-adjacent keys are copied verbatim.
        $this->assertSame($contentJson['terms'], $contract->terms_json['terms']);
        $this->assertSame($contentJson['exclusions'], $contract->terms_json['exclusions']);
        $this->assertSame($contentJson['timeline'], $contract->terms_json['timeline']);
        $this->assertSame($contentJson['payment_plan'], $contract->terms_json['payment_plan']);

        // The two contract-only slots are explicit null keys, not omitted.
        $this->assertArrayHasKey('warranty_period', $contract->terms_json);
        $this->assertArrayHasKey('cancellation_policy', $contract->terms_json);
        $this->assertNull($contract->terms_json['warranty_period']);
        $this->assertNull($contract->terms_json['cancellation_policy']);

        // Presentation-only content is NOT copied into the contract's terms.
        $this->assertArrayNotHasKey('cover_note', $contract->terms_json);
        $this->assertArrayNotHasKey('scope', $contract->terms_json);

        // Same via the API response shape.
        $data = $response->json('data.terms_json');
        $this->assertSame($contentJson['terms'], $data['terms']);
        $this->assertArrayHasKey('warranty_period', $data);
        $this->assertNull($data['warranty_period']);
    }

    public function test_missing_content_json_keys_are_seeded_as_null_rather_than_erroring(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        // A proposal whose content_json has none of the terms-adjacent keys at all.
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'content_json' => ['cover_note' => 'Hello'],
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);

        $terms = $response->json('data.terms_json');
        foreach (['terms', 'exclusions', 'timeline', 'payment_plan', 'warranty_period', 'cancellation_policy'] as $key) {
            $this->assertArrayHasKey($key, $terms);
            $this->assertNull($terms[$key]);
        }
    }
}

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
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RBAC coverage for Sprint 4's proposals surface, per ProposalVersionController's docblock:
 * reads (index/show/pdf) require only an active membership; mutations (store/update/send)
 * require Permissions::MANAGE_BOQ — same posture PricingRbacTest already established for
 * Sprint 3. Public endpoints have no RBAC by design (the token IS the auth) but must carry the
 * `public-links` throttle middleware, checked here at the route-registration level (see this
 * class's docblock note on why we don't literally trigger the rate limit).
 */
class ProposalRbacTest extends TestCase
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

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    public function test_read_only_member_can_list_and_view_but_not_create_update_or_send(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->seedBoqItem($project);

        // Seed the existing draft directly via the model factory rather than through an
        // authenticated HTTP call as a second ("creator") user. This test only ever
        // authenticates ONE user (readOnlyUser) over real HTTP.
        //
        // Deliberate choice, not a shortcut: this codebase's Sanctum-token-based test harness
        // has a reproducible quirk where issuing two real HTTP requests as two DIFFERENT users
        // against the SAME mutating route within one test method can leave the second request
        // resolving as the FIRST user internally — even with Auth::forgetGuards() called
        // between them (confirmed by direct instrumentation: Auth::guard('sanctum')->user()
        // returned the first user's id on the second call despite the second request
        // genuinely carrying the second user's bearer token). This reproduces specifically for
        // two users in the SAME organization hitting POST .../proposals back-to-back; it does
        // NOT reproduce for read-only routes, for users in different organizations, or when
        // only one user authenticates via HTTP per test method (as done here and in
        // ProposalTenantIsolationTest, which sidesteps it the same way). This is a test-harness
        // artifact, not a production vulnerability — flagged in this report's findings, not
        // fixed here (out of scope for a test suite to patch Sanctum/Laravel's guard caching).
        $id = ProposalVersion::factory()->create(['project_id' => $project->id, 'version_no' => 1])->id;

        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Reads succeed ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/proposals/{$id}")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/proposals/{$id}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        // --- Writes are forbidden ---
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(403);
        $this->assertDatabaseCount('proposal_versions', 1); // only the creator's draft

        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['content_json' => ['cover_note' => 'nope']])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson("/api/v1/proposals/{$id}/send")
            ->assertStatus(403);
        $this->assertDatabaseCount('signed_links', 0);
    }

    public function test_member_with_manage_boq_can_perform_every_proposal_write_operation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->seedBoqItem($project);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $create = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201);
        $id = $create->json('data.id');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['content_json' => ['cover_note' => 'Updated']])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson("/api/v1/proposals/{$id}/send")
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_to_internal_proposal_routes_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);

        $this->getJson("/api/v1/projects/{$project->id}/proposals")->assertStatus(401);
        $this->postJson("/api/v1/projects/{$project->id}/proposals")->assertStatus(401);
    }

    /**
     * Public endpoints intentionally have no permission gate — the signed token is the sole
     * authorization mechanism (per PublicProposalController's docblock). What DOES need
     * verifying is that the `public-links` rate limiter (AppServiceProvider::registerRateLimiters(),
     * Limit::perMinute(30)->by(ip)) is actually attached to all four public routes. We assert
     * this at the route-registration level rather than firing 31 real requests in a loop: the
     * limiter's numeric threshold is already exercised indirectly by every other public-endpoint
     * test in this suite succeeding well under 30 req/min, and a literal throttle-triggering
     * test would be slow/flaky (RateLimiter is keyed by IP+cache, shared across parallel test
     * runs) for very little extra confidence over confirming the middleware is wired at all.
     */
    public function test_all_four_public_proposal_routes_carry_the_public_links_rate_limiter(): void
    {
        $expectedUris = [
            ['GET', 'api/v1/public/proposals/{token}'],
            ['POST', 'api/v1/public/proposals/{token}/approve'],
            ['POST', 'api/v1/public/proposals/{token}/request-changes'],
            ['GET', 'api/v1/public/proposals/{token}/pdf'],
        ];

        foreach ($expectedUris as [$method, $uri]) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
            );

            $this->assertNotNull($route, "Route not found: {$method} {$uri}");
            $this->assertContains(
                'throttle:public-links',
                $route->gatherMiddleware(),
                "{$method} {$uri} is missing the public-links throttle middleware"
            );
            // And confirm it's genuinely NOT behind auth:sanctum/tenant — a public surface.
            $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('tenant', $route->gatherMiddleware());
        }
    }
}

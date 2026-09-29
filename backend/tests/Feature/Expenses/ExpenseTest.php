<?php

namespace Tests\Feature\Expenses;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD "Expenses: project expenses, receipts, supplier linkage." Mirrors
 * PaymentReceiptUploadTest's structure/rationale.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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

    /** @return array{0: Organization, 1: Project, 2: User} */
    private function setUpProject(array $permissions = [Permissions::MANAGE_BOQ => true, Permissions::VIEW_FINANCIALS => true]): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$organization, $project, $user];
    }

    public function test_store_records_an_expense_with_supplier_linkage(): void
    {
        Storage::fake('local');
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/expenses",
            [
                'supplier_id' => $supplier->id,
                'category' => 'material',
                'description' => 'Cement bags',
                'amount' => '3500.00',
                'expense_date' => now()->toDateString(),
            ]
        );

        $response->assertStatus(201);
        $this->assertSame($supplier->id, $response->json('data.supplier.id'));
        $this->assertFalse($response->json('data.has_receipt'));

        $expense = ProjectExpense::first();
        $this->assertSame($organization->id, $expense->organization_id);
        $this->assertSame($project->id, $expense->project_id);
    }

    public function test_store_with_receipt_uploads_and_streams_it_back(): void
    {
        Storage::fake('local');
        [, $project, $user] = $this->setUpProject();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/expenses",
            [
                'category' => 'labor',
                'description' => 'Painters, week 3',
                'amount' => '4200.00',
                'expense_date' => now()->toDateString(),
                'receipt' => UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG_BASE64)),
            ]
        );

        $create->assertStatus(201);
        $this->assertTrue($create->json('data.has_receipt'));
        $this->assertArrayNotHasKey('receipt_url', $create->json('data'));

        $response = $this->withHeaders($this->authHeader($user))
            ->get('/api/v1/expenses/'.$create->json('data.id').'/receipt');

        $response->assertStatus(200);
        $this->assertStringContainsString('image/png', $response->headers->get('content-type'));
    }

    public function test_index_lists_expenses_newest_first_by_date(): void
    {
        [, $project, $user] = $this->setUpProject();
        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $project->organization_id, 'expense_date' => '2026-01-01']);
        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $project->organization_id, 'expense_date' => '2026-06-01']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/expenses");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('2026-06-01', substr($response->json('data.0.expense_date'), 0, 10));
    }

    public function test_store_without_manage_boq_permission_is_rejected_with_403(): void
    {
        [, $project, $user] = $this->setUpProject([Permissions::MANAGE_BOQ => false, Permissions::VIEW_FINANCIALS => true]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/expenses",
            ['category' => 'material', 'description' => 'x', 'amount' => '10.00', 'expense_date' => now()->toDateString()]
        )->assertStatus(403);
    }

    public function test_index_without_view_financials_permission_is_rejected_with_403(): void
    {
        [, $project, $user] = $this->setUpProject([Permissions::MANAGE_BOQ => true, Permissions::VIEW_FINANCIALS => false]);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/expenses")
            ->assertStatus(403);
    }

    public function test_a_member_of_another_organization_cannot_see_this_projects_expenses(): void
    {
        [, $project] = $this->setUpProject();
        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $project->organization_id]);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_BOQ => true, Permissions::VIEW_FINANCIALS => true]);

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/projects/{$project->id}/expenses")
            ->assertStatus(404);
    }
}

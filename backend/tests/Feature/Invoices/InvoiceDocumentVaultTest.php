<?php

namespace Tests\Feature\Invoices;

use App\Models\Client;
use App\Models\InvoiceDocument;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD v3 §7 "Invoice / Receipt Vault" (InvoiceUploadService, InvoiceDocumentController).
 * Storage::fake('local') intercepts the real disk, same convention as ProjectMediaTest/
 * PaymentReceiptUploadTest — genuine magic-byte PDF/PNG content since StoreInvoiceDocumentRequest
 * validates the actual file content, not just the declared extension.
 */
class InvoiceDocumentVaultTest extends TestCase
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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_PROCUREMENT => true]);
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    /**
     * `$variant` pads the PNG's trailing bytes so two calls with different variants produce
     * genuinely different file_fingerprint hashes (PNG decoders/mime-sniffers only look at the
     * leading magic bytes, so trailing padding doesn't break "is this a PNG" detection) — tests
     * that intentionally re-upload the SAME bytes to exercise fingerprint-based duplicate
     * detection pass no variant (or the same one) on both calls; tests that need two genuinely
     * different files pass different variants.
     */
    private function pngFile(string $name = 'invoice.png', string $variant = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64).$variant);
    }

    public function test_store_uploads_an_invoice_and_stores_the_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile(),
                'supplier_id' => $supplier->id,
                'invoice_number' => 'INV-1001',
                'invoice_date' => '2026-01-15',
                'amount' => '5000.00',
                'vat_amount' => '700.00',
            ]);

        $response->assertStatus(201);
        $this->assertSame('5000.00', $response->json('data.amount'));
        $this->assertSame('unpaid', $response->json('data.payment_status'));
        $this->assertFalse($response->json('data.is_superseded'));
        $this->assertDatabaseCount('invoice_documents', 1);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_reuploading_the_exact_same_file_is_rejected_as_a_duplicate(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('first.png'),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->assertStatus(201);

        // Same bytes, different filename — file_fingerprint (a content hash) still matches.
        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('second.png'),
                'invoice_date' => '2026-02-01',
                'amount' => '999.00',
            ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'duplicate_invoice');
        $this->assertDatabaseCount('invoice_documents', 1);
    }

    public function test_same_vendor_invoice_number_amount_and_date_is_rejected_even_with_a_different_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('a.png'),
                'supplier_id' => $supplier->id,
                'invoice_number' => 'INV-2002',
                'invoice_date' => '2026-01-15',
                'amount' => '3000.00',
            ])->assertStatus(201);

        // Different file bytes (a real PDF this time), same vendor/invoice_number/amount/date.
        $pdf = UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n");
        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $pdf,
                'supplier_id' => $supplier->id,
                'invoice_number' => 'INV-2002',
                'invoice_date' => '2026-01-15',
                'amount' => '3000.00',
            ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'duplicate_invoice');
        $this->assertDatabaseCount('invoice_documents', 1);
    }

    public function test_a_different_invoice_is_not_flagged_as_a_duplicate(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('a.png', 'v1'),
                'invoice_date' => '2026-01-15',
                'amount' => '3000.00',
            ])->assertStatus(201);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('b.png', 'v2'),
                'invoice_date' => '2026-02-20',
                'amount' => '4500.00',
            ])->assertStatus(201);

        $this->assertDatabaseCount('invoice_documents', 2);
    }

    public function test_supersede_creates_a_new_row_and_leaves_the_original_untouched(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $original = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('a.png', 'v1'),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->json('data');

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/invoices/{$original['id']}/supersede", [
                'file' => $this->pngFile('corrected.png', 'v2'),
                'invoice_date' => '2026-01-15',
                'amount' => '1250.00',
            ]);

        $response->assertStatus(201);
        $this->assertSame($original['id'], $response->json('data.supersedes_id'));
        $this->assertSame('1250.00', $response->json('data.amount'));

        // Original row's own amount is untouched — a correction never edits it in place.
        $originalModel = InvoiceDocument::find($original['id']);
        $this->assertSame(0, bccomp('1000.00', (string) $originalModel->amount, 2));
        $this->assertDatabaseCount('invoice_documents', 2);
    }

    public function test_index_hides_superseded_versions_by_default(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $original = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile('a.png', 'v1'),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->json('data');

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/invoices/{$original['id']}/supersede", [
                'file' => $this->pngFile('corrected.png', 'v2'),
                'invoice_date' => '2026-01-15',
                'amount' => '1250.00',
            ])->assertStatus(201);

        $currentOnly = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/invoices")
            ->json('data');
        $this->assertCount(1, $currentOnly);
        $this->assertSame('1250.00', $currentOnly[0]['amount']);

        $all = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/invoices?current=0")
            ->json('data');
        $this->assertCount(2, $all);
    }

    public function test_file_endpoint_streams_the_uploaded_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $invoiceId = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile(),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->json('data.id');

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/invoices/{$invoiceId}/file")
            ->assertStatus(200);
    }

    public function test_mutations_without_manage_procurement_permission_are_rejected_with_403(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $this->pngFile(),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->assertStatus(403);
    }

    public function test_a_member_of_another_organization_cannot_see_or_supersede_this_invoice(): void
    {
        Storage::fake('local');
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $projectA = $this->projectIn($organizationA);
        $userA = $this->fullAccessUser($organizationA);
        $userB = $this->fullAccessUser($organizationB);

        $invoiceId = $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectA->id}/invoices", [
                'file' => $this->pngFile(),
                'invoice_date' => '2026-01-15',
                'amount' => '1000.00',
            ])->json('data.id');

        // Switching authenticated users mid-test via two HTTP calls can otherwise have the
        // second resolve as the first internally (a known Sanctum test-harness quirk in this
        // codebase, not a production bug — see PricingTenantIsolationTest/ProposalRbacTest for
        // the same workaround).
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withHeaders($this->authHeader($userB))
            ->getJson("/api/v1/invoices/{$invoiceId}/file")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($userB))
            ->postJson("/api/v1/invoices/{$invoiceId}/supersede", [
                'file' => $this->pngFile(),
                'invoice_date' => '2026-01-15',
                'amount' => '999.00',
            ])->assertStatus(404);
    }
}

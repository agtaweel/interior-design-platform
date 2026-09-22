<?php

namespace Tests\Feature\Payments;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 6 -> receipt file upload (PaymentRecordingService::storeReceipt(),
 * PaymentController::receipt()). Storage::fake('local') intercepts the `Storage::disk('local')`
 * calls the implementation makes, so nothing touches the real dev-environment disk.
 *
 * Uses UploadedFile::fake()->createWithContent() with genuine magic-byte content (a real 1x1 PNG,
 * a real minimal PDF header) rather than fake()->image()/->create() (which populate an
 * arbitrary/declared MIME rather than real file bytes) — StorePaymentRequest's `mimes:` rule
 * guesses the MIME from actual file content via Symfony's MIME guesser, per its own docblock
 * ("validate mime type, don't just trust the extension"), so the test fixtures must be real
 * bytes of the claimed type for the validation-acceptance assertions to mean anything.
 */
class PaymentReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    // Smallest valid PNG (1x1 transparent pixel).
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
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
    }

    /** @return array{0: PaymentSchedule, 1: User, 2: Organization} */
    private function setUpSchedule(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'percentage' => null,
            'amount' => '1000.00',
            'status' => 'pending',
        ]);
        $user = $this->fullAccessUser($organization);

        return [$schedule, $user, $organization];
    }

    private function pngFile(string $name = 'receipt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    private function pdfFile(string $name = 'receipt.pdf'): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_uploading_a_receipt_stores_it_and_has_receipt_becomes_true_in_the_resource(): void
    {
        Storage::fake('local');
        [$schedule, $user, $organization] = $this->setUpSchedule();

        $response = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
                'receipt' => $this->pngFile(),
            ]
        );

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.has_receipt'));
        $this->assertArrayNotHasKey('receipt_url', $response->json('data'));

        $payment = Payment::first();
        $this->assertNotNull($payment->receipt_url);
        $this->assertStringStartsWith("receipts/{$organization->id}/", $payment->receipt_url);
        Storage::disk('local')->assertExists($payment->receipt_url);
    }

    public function test_get_receipt_streams_the_correct_file_with_the_correct_content_type(): void
    {
        Storage::fake('local');
        [$schedule, $user] = $this->setUpSchedule();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
                'receipt' => $this->pngFile(),
            ]
        )->assertStatus(201);

        $paymentId = $create->json('data.id');

        $response = $this->withHeaders($this->authHeader($user))
            ->get("/api/v1/payments/{$paymentId}/receipt");

        $response->assertStatus(200);
        $this->assertStringContainsString('image/png', $response->headers->get('content-type'));
        $this->assertSame(base64_decode(self::PNG_BASE64), $response->streamedContent());
    }

    public function test_get_receipt_for_a_pdf_upload_returns_application_pdf_content_type(): void
    {
        Storage::fake('local');
        [$schedule, $user] = $this->setUpSchedule();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
                'receipt' => $this->pdfFile(),
            ]
        )->assertStatus(201);

        $response = $this->withHeaders($this->authHeader($user))
            ->get('/api/v1/payments/'.$create->json('data.id').'/receipt');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_payment_with_no_receipt_returns_404_on_the_receipt_route(): void
    {
        Storage::fake('local');
        [$schedule, $user] = $this->setUpSchedule();

        $create = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ]
        )->assertStatus(201);
        $this->assertFalse($create->json('data.has_receipt'));

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/payments/'.$create->json('data.id').'/receipt');

        $response->assertStatus(404);
    }

    public function test_uploading_a_disallowed_file_type_is_rejected_with_422(): void
    {
        Storage::fake('local');
        [$schedule, $user] = $this->setUpSchedule();

        $response = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
                'receipt' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ'.str_repeat('x', 100)),
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('validation_failed', $response->json('error.code'));
        $this->assertArrayHasKey('receipt', $response->json('error.details'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_uploading_an_oversized_receipt_is_rejected_with_422(): void
    {
        Storage::fake('local');
        [$schedule, $user] = $this->setUpSchedule();

        // StorePaymentRequest caps at 10240 KB (10MB) via the `max:10240` rule. 10241 KB of
        // real PNG-prefixed bytes so the size rule (not the mimes rule) is what fires.
        $oversized = UploadedFile::fake()->create('big.png', 10241, 'image/png');

        $response = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/payment-schedules/{$schedule->id}/payments",
            [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
                'receipt' => $oversized,
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('validation_failed', $response->json('error.code'));
        $this->assertArrayHasKey('receipt', $response->json('error.details'));
        $this->assertDatabaseCount('payments', 0);
    }
}

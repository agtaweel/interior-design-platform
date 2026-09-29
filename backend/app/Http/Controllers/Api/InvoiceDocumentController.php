<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceDocumentRequest;
use App\Http\Requests\SupersedeInvoiceDocumentRequest;
use App\Http\Resources\InvoiceDocumentResource;
use App\Models\InvoiceDocument;
use App\Models\Project;
use App\Services\Invoices\DuplicateInvoiceException;
use App\Services\Invoices\InvoiceUploadService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET/POST /projects/{project}/invoices, POST /invoices/{invoice}/supersede,
 * GET /invoices/{invoice}/file (BRD v3 §7 "Invoice / Receipt Vault").
 *
 * {project} follows the same manual-lookup convention as every other project-nested controller.
 * InvoiceDocument carries organization_id directly (like Payment/ProjectExpense), so
 * InvoiceDocument::find() is already tenant-scoped via BelongsToOrganization's global scope — no
 * manual re-resolution needed for {invoice}.
 *
 * Every endpoint here requires Permissions::MANAGE_PROCUREMENT — see that constant's docblock
 * and StoreInvoiceDocumentRequest's for why (gates both reads and writes, same posture as
 * Suppliers/POs).
 */
class InvoiceDocumentController extends Controller
{
    public function __construct(private readonly InvoiceUploadService $uploadService) {}

    /**
     * `current` (default true): only the latest version of each invoice, hiding anything
     * superseded by a later correction. Pass `?current=0` to see the full history including
     * stale/corrected rows — e.g. for an audit trail view.
     */
    public function index(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $query = InvoiceDocument::query()
            ->where('project_id', $projectModel->id)
            ->with(['uploadedBy', 'supersededBy'])
            ->orderByDesc('invoice_date');

        if ($request->boolean('current', true)) {
            $query->current();
        }

        return response()->json([
            'data' => InvoiceDocumentResource::collection($query->get()),
        ]);
    }

    public function store(StoreInvoiceDocumentRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        try {
            $invoice = $this->uploadService->upload(
                $projectModel,
                $request->validated(),
                $request->file('file'),
                $request->user()->id,
            );
        } catch (DuplicateInvoiceException $e) {
            return $this->duplicateError($e);
        }

        return response()->json([
            'data' => new InvoiceDocumentResource($invoice->load(['uploadedBy', 'supersededBy'])),
        ], 201);
    }

    /**
     * POST /invoices/{invoice}/supersede — a correction, never an in-place edit (BRD v3 §7).
     * Same body shape as store() (a full replacement invoice + file) — {project} is resolved
     * from the ORIGINAL invoice's own project_id, not a route parameter, since a correction can
     * only ever target the same project it originated in.
     */
    public function supersede(SupersedeInvoiceDocumentRequest $request, string $invoice): JsonResponse
    {
        $original = InvoiceDocument::query()->find($invoice);

        if (! $original) {
            return $this->notFound();
        }

        try {
            $superseding = $this->uploadService->supersede(
                $original,
                $request->validated(),
                $request->file('file'),
                $request->user()->id,
            );
        } catch (DuplicateInvoiceException $e) {
            return $this->duplicateError($e);
        }

        return response()->json([
            'data' => new InvoiceDocumentResource($superseding->load(['uploadedBy', 'supersededBy'])),
        ], 201);
    }

    public function file(Request $request, string $invoice): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $invoiceModel = InvoiceDocument::query()->find($invoice);

        if (! $invoiceModel) {
            return $this->notFound();
        }

        $media = $invoiceModel->getFirstMedia(InvoiceDocument::FILE_COLLECTION);

        if (! $media) {
            return $this->notFound();
        }

        return $media->toInlineResponse($request);
    }

    private function duplicateError(DuplicateInvoiceException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'duplicate_invoice',
                'message' => $e->getMessage(),
                'details' => ['existing_invoice_id' => $e->existingInvoiceId],
            ],
        ], 409);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
                'details' => (object) [],
            ],
        ], 404);
    }
}

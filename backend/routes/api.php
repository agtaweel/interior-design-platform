<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoqCategoryController;
use App\Http\Controllers\Api\BoqController;
use App\Http\Controllers\Api\BoqImportController;
use App\Http\Controllers\Api\BoqItemController;
use App\Http\Controllers\Api\BoqTemplateCategoryController;
use App\Http\Controllers\Api\BoqTemplateController;
use App\Http\Controllers\Api\BoqTemplateItemController;
use App\Http\Controllers\Api\ChangeOrderController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationMemberController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentScheduleController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\PricingRuleController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectFinancialsController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\ProjectServiceController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ProposalVersionController;
use App\Http\Controllers\Api\PublicChangeOrderController;
use App\Http\Controllers\Api\PublicProposalController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Base URL is /api/v1 per docs/PROJECT_CONTEXT.md (the `api` prefix comes from
| bootstrap/app.php's withRouting(api: ...), the `v1` group below). All routes require
| Sanctum auth except /auth/login; everything that touches tenant data additionally requires
| the `tenant` middleware, which resolves + verifies the current organization (see
| App\Http\Middleware\ResolveTenantContext). /public/... routes (Sprint 4 onward) are the only
| ones that skip auth entirely, using signed tokens instead (App\Support\PublicLinks).
|
| Note on route parameters: {client}/{property}/{project} below are deliberately plain
| (string) route parameters, NOT type-hinted Eloquent route-model-bound parameters. Laravel's
| SubstituteBindings middleware (which performs implicit model binding) runs before the
| `tenant` middleware in the priority-sorted pipeline (see
| Illuminate\Foundation\Http\Kernel::$middlewarePriority) — so an implicitly-bound Project/
| Client/Property would be resolved BEFORE TenantContext is set, meaning OrganizationScope
| would not yet be applying and the binding could resolve a record from ANY organization.
| Controllers resolve these ids manually instead (e.g. `Client::find($client)`), which runs
| after the full middleware stack — see ClientController/PropertyController/ProjectController
| docblocks. This mirrors the existing convention in OrganizationMemberController, which does
| the same manual lookup for its nested {member} parameter for the identical reason.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Organization-nested routes: {organization} is both the resource path and the
        // explicit tenant-context signal for the `tenant` middleware.
        Route::middleware('tenant')->group(function () {
            // Sprint 8 "Settings" (S22, scoped down — PROJECT_CONTEXT.md): organization profile
            // PATCH (gated behind Permissions::MANAGE_ORGANIZATION inside
            // UpdateOrganizationRequest::authorize()) and the members list (no extra permission
            // beyond active membership — see OrganizationMemberController::index()'s docblock).
            Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
            Route::get('/organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
            Route::post('/organizations/{organization}/members/invite', [OrganizationMemberController::class, 'invite']);
            Route::patch('/organizations/{organization}/members/{member}', [OrganizationMemberController::class, 'update']);

            Route::get('/clients', [ClientController::class, 'index']);
            Route::post('/clients', [ClientController::class, 'store']);
            Route::get('/clients/{client}', [ClientController::class, 'show']);
            Route::patch('/clients/{client}', [ClientController::class, 'update']);
            Route::post('/clients/{client}/properties', [PropertyController::class, 'store']);

            Route::get('/properties/{property}', [PropertyController::class, 'show']);

            Route::get('/projects', [ProjectController::class, 'index']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::patch('/projects/{project}', [ProjectController::class, 'update']);
            Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store']);
            Route::post('/projects/{project}/services', [ProjectServiceController::class, 'store']);

            // Rooms (Sprint 2 gap fix, PROJECT_CONTEXT.md). Not nested under /boq because a
            // room is a structural property of the project itself (see Room model docblock),
            // not a BOQ record — but it's grouped here since BOQ line items are its only
            // consumer today (boq_items.room_id). {project} follows the same manual-lookup
            // convention as above. GET requires only an active membership (read); POST requires
            // Permissions::MANAGE_BOQ (checked inside StoreRoomRequest::authorize()), matching
            // the BOQ write endpoints below since rooms only exist to organize BOQ line items.
            Route::get('/projects/{project}/rooms', [RoomController::class, 'index']);
            Route::post('/projects/{project}/rooms', [RoomController::class, 'store']);

            // BOQ (Sprint 2, PROJECT_CONTEXT.md). {project} follows the same manual-lookup
            // convention as above. {item} (PATCH/DELETE) is likewise plain/manual — see
            // BoqItemController's docblock for why implicit binding is unsafe here even though
            // BoqItem itself carries no organization_id to scope by directly.
            Route::get('/projects/{project}/boq', [BoqController::class, 'index']);
            Route::get('/projects/{project}/boq/export', [BoqController::class, 'export']);
            Route::post('/projects/{project}/boq/import', [BoqImportController::class, 'import']);
            Route::post('/projects/{project}/boq/categories', [BoqCategoryController::class, 'store']);
            Route::post('/projects/{project}/boq/items', [BoqItemController::class, 'store']);
            Route::post('/projects/{project}/boq/apply-template/{templateCategory}', [BoqTemplateController::class, 'apply']);
            Route::patch('/boq/items/{item}', [BoqItemController::class, 'update']);
            Route::delete('/boq/items/{item}', [BoqItemController::class, 'destroy']);

            // Pricing (Sprint 3, PROJECT_CONTEXT.md). {project} follows the same manual-lookup
            // convention as above. /pricing/rules/{rule} (PATCH/DELETE) is likewise plain/
            // manual, not nested under /projects/{project} — see PricingRuleController's
            // docblock for why implicit binding is unsafe here even though PricingRule itself
            // carries no organization_id to scope by directly. GET routes (rules index,
            // breakdown) require only an active membership; mutations (rules
            // create/update/delete, recalculate) require Permissions::MANAGE_BOQ — pricing is
            // part of the same designer/estimator workflow as BOQ editing (see
            // StorePricingRuleRequest's docblock).
            Route::get('/projects/{project}/pricing/rules', [PricingRuleController::class, 'index']);
            Route::post('/projects/{project}/pricing/rules', [PricingRuleController::class, 'store']);
            Route::patch('/pricing/rules/{rule}', [PricingRuleController::class, 'update']);
            Route::delete('/pricing/rules/{rule}', [PricingRuleController::class, 'destroy']);
            Route::post('/projects/{project}/pricing/recalculate', [PricingController::class, 'recalculate']);
            Route::get('/projects/{project}/pricing/breakdown', [PricingController::class, 'breakdown']);

            // Organization-level BOQ templates (mirrors the project-scoped BOQ tables — see
            // BoqTemplateCategory/BoqTemplateItem docblocks). Not a PRD-mandated screen this
            // sprint, just the API surface "apply template to project" needs to be usable.
            Route::get('/boq-templates/categories', [BoqTemplateCategoryController::class, 'index']);
            Route::post('/boq-templates/categories', [BoqTemplateCategoryController::class, 'store']);
            Route::post('/boq-templates/categories/{category}/items', [BoqTemplateItemController::class, 'store']);

            // Proposals (Sprint 4, PROJECT_CONTEXT.md). {project}/{proposal} follow the same
            // manual-lookup convention as every other project-nested/cross-cutting controller
            // above — see ProposalVersionController's docblock. GET routes (index/show/pdf)
            // require only an active membership; mutations (store/update/send) require
            // Permissions::MANAGE_BOQ, checked inside the Store/Update FormRequests'
            // authorize() or, for send() (no request body to validate), via an explicit
            // Gate::authorize() call matching BoqItemController::destroy()'s pattern.
            Route::get('/projects/{project}/proposals', [ProposalVersionController::class, 'index']);
            Route::post('/projects/{project}/proposals', [ProposalVersionController::class, 'store']);
            Route::get('/proposals/{proposal}', [ProposalVersionController::class, 'show']);
            Route::patch('/proposals/{proposal}', [ProposalVersionController::class, 'update']);
            Route::post('/proposals/{proposal}/send', [ProposalVersionController::class, 'send']);
            Route::get('/proposals/{proposal}/pdf', [ProposalVersionController::class, 'pdf']);

            // Contracts (Sprint 5, PROJECT_CONTEXT.md). {project}/{proposal}/{contract} follow
            // the same manual-lookup convention as above — see ContractController's docblock.
            // Contract creation IS the signing act (no separate public/token signing flow —
            // the client already OTP-approved the proposal in Sprint 4), so from-proposal is an
            // authenticated, internal-only POST just like every other route in this group.
            // GET routes (index/show/pdf) require only an active membership; from-proposal/update
            // require Permissions::MANAGE_BOQ (see class docblock for why this reuses proposals'
            // permission rather than introducing manage_contracts). No public contract-viewing
            // route exists in the PRD — the PDF here is internal-only, shared manually like the
            // proposal link.
            Route::get('/projects/{project}/contracts', [ContractController::class, 'index']);
            Route::post('/projects/{project}/contracts/from-proposal/{proposal}', [ContractController::class, 'fromProposal']);
            Route::get('/contracts/{contract}', [ContractController::class, 'show']);
            Route::patch('/contracts/{contract}', [ContractController::class, 'update']);
            Route::get('/contracts/{contract}/pdf', [ContractController::class, 'pdf']);

            // Sprint 6 (PROJECT_CONTEXT.md "Payments"): schedule/payment mutations require
            // Permissions::MANAGE_BOQ (same commercial-workflow convention as BOQ/pricing/
            // proposals/contracts); reads of schedules/payments/financials require
            // Permissions::VIEW_FINANCIALS instead of just active membership — this is the
            // sprint that finally activates that permission (seeded since Sprint 1, unused
            // until now) since profit-adjacent data is exactly what it exists to gate.
            Route::post('/contracts/{contract}/payment-schedules', [PaymentScheduleController::class, 'store']);
            Route::get('/contracts/{contract}/payment-schedules', [PaymentScheduleController::class, 'index']);
            Route::post('/payment-schedules/{schedule}/payments', [PaymentController::class, 'store']);
            Route::get('/payment-schedules/{schedule}/payments', [PaymentController::class, 'index']);
            Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt']);
            Route::get('/projects/{project}/financials', [ProjectFinancialsController::class, 'show']);

            // Change Orders (Sprint 7, PROJECT_CONTEXT.md). {project}/{changeOrder} follow the
            // same manual-lookup convention as above — see ChangeOrderController's docblock.
            // GET routes (index/show) require only an active membership; mutations
            // (store/update/send/apply) require Permissions::MANAGE_BOQ — price_delta is a
            // pricing concept (same tier as BOQ/pricing-rule/proposal/contract data), not a
            // collected-money concept, so this deliberately does NOT reuse Sprint 6's
            // VIEW_FINANCIALS gate for reads.
            Route::get('/projects/{project}/change-orders', [ChangeOrderController::class, 'index']);
            Route::post('/projects/{project}/change-orders', [ChangeOrderController::class, 'store']);
            Route::get('/change-orders/{changeOrder}', [ChangeOrderController::class, 'show']);
            Route::patch('/change-orders/{changeOrder}', [ChangeOrderController::class, 'update']);
            Route::post('/change-orders/{changeOrder}/send', [ChangeOrderController::class, 'send']);
            Route::post('/change-orders/{changeOrder}/apply', [ChangeOrderController::class, 'apply']);

            // Notifications (Sprint 8, PROJECT_CONTEXT.md). No Permissions::* gate on any of
            // these three — a user only ever sees/mutates their OWN notification inbox (checked
            // per-row inside NotificationController, not via a Gate ability); the `tenant`
            // middleware still applies so Notification's OrganizationScope has a context to
            // scope against.
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

            // Reports (Sprint 8, PROJECT_CONTEXT.md, S21). Organization-wide, not
            // project-nested — both gated behind Permissions::VIEW_FINANCIALS (see
            // ReportController's docblock). CSV export is its own route, mirroring BOQ's
            // existing /boq vs /boq/export precedent.
            Route::get('/reports/summary', [ReportController::class, 'summary']);
            Route::get('/reports/projects', [ReportController::class, 'projects']);
            Route::get('/reports/projects/export', [ReportController::class, 'projectsExport']);
        });
    });

    // Public client proposal portal (Sprint 4, PROJECT_CONTEXT.md S11) — deliberately OUTSIDE
    // both the auth:sanctum and `tenant` middleware groups above: these are unauthenticated,
    // token-authenticated-instead endpoints (App\Support\PublicLinks\SignedLinkService), per
    // the locked product decision (secure link + OTP, no login). `public-links` is the reusable
    // rate limiter AppServiceProvider reserved for exactly this kind of enumerable-by-token
    // public surface (originally named there for "signed proposal / change-order links, OTP
    // requests" — this is its first real consumer).
    Route::middleware('throttle:public-links')->group(function () {
        Route::get('/public/proposals/{token}', [PublicProposalController::class, 'show']);
        Route::post('/public/proposals/{token}/approve', [PublicProposalController::class, 'approve']);
        Route::post('/public/proposals/{token}/request-changes', [PublicProposalController::class, 'requestChanges']);
        Route::get('/public/proposals/{token}/pdf', [PublicProposalController::class, 'pdf']);

        // Public client change-order approval page (Sprint 7, PROJECT_CONTEXT.md S14) —
        // deliberately OUTSIDE both the auth:sanctum and `tenant` middleware groups above, same
        // token-authenticated-instead posture as the public proposal routes.
        Route::get('/public/change-orders/{token}', [PublicChangeOrderController::class, 'show']);
        Route::post('/public/change-orders/{token}/approve', [PublicChangeOrderController::class, 'approve']);
        Route::post('/public/change-orders/{token}/reject', [PublicChangeOrderController::class, 'reject']);
    });
});

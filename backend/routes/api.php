<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoqCategoryController;
use App\Http\Controllers\Api\BoqController;
use App\Http\Controllers\Api\BoqImportController;
use App\Http\Controllers\Api\BoqItemController;
use App\Http\Controllers\Api\BoqTemplateCategoryController;
use App\Http\Controllers\Api\BoqTemplateController;
use App\Http\Controllers\Api\BoqTemplateItemController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrganizationMemberController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\ProjectServiceController;
use App\Http\Controllers\Api\PropertyController;
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
| App\Http\Middleware\ResolveTenantContext). /public/... routes (later sprints) are the only
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

            // Organization-level BOQ templates (mirrors the project-scoped BOQ tables — see
            // BoqTemplateCategory/BoqTemplateItem docblocks). Not a PRD-mandated screen this
            // sprint, just the API surface "apply template to project" needs to be usable.
            Route::get('/boq-templates/categories', [BoqTemplateCategoryController::class, 'index']);
            Route::post('/boq-templates/categories', [BoqTemplateCategoryController::class, 'store']);
            Route::post('/boq-templates/categories/{category}/items', [BoqTemplateItemController::class, 'store']);
        });
    });
});

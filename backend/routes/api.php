<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrganizationMemberController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\ProjectServiceController;
use App\Http\Controllers\Api\PropertyController;
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
        });
    });
});

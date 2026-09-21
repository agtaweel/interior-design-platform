<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationMemberController;
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
        });
    });
});

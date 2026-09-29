<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v3 §5/§20 "Platform Owner / Super Admin": a cross-tenant role that sits ABOVE the
 * organization-scoped RBAC model entirely (App\Support\Authorization\Permissions/Role are all
 * organization-scoped — a Platform Owner is not, by definition, since they need visibility
 * across every organization on the platform). A boolean flag on `users` (rather than another
 * organization-scoped Role) is deliberate: this user doesn't belong to a "role" WITHIN any
 * particular organization for this purpose — they may or may not also be a member of specific
 * organizations independently (unaffected by this flag either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_owner')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_owner');
        });
    }
};

<?php

namespace App\Models;

use Database\Factories\BoqTemplateApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ Master Catalog + Standard Templates — one row per "a template version was applied to a
 * project" event. See the migration's docblock. Directly organization-scoped (carries
 * organization_id itself), same convention as AuditLog/Approval — not auto-filled via
 * BelongsToOrganization since this is only ever written by BoqTemplateCommitService with an
 * explicit organization_id, never from a client request body.
 */
#[Fillable(['organization_id', 'project_id', 'template_id', 'template_version_id', 'applied_by', 'item_count'])]
class BoqTemplateApplication extends Model
{
    /** @use HasFactory<BoqTemplateApplicationFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BoqTemplate::class, 'template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(BoqTemplateVersion::class, 'template_version_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}

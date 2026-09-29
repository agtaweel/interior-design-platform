<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/media. Multipart body: `collection` (which of
 * Project::MEDIA_COLLECTIONS this file belongs to), `file`, optional `caption`. Mutation gated
 * behind Permissions::MANAGE_PROJECTS — attachments are project-lifecycle content (same tier as
 * project name/status/services), not a BOQ/pricing concern, so this deliberately does NOT reuse
 * MANAGE_BOQ the way rooms/proposals/contracts do (see ProjectController's own docblock — writes
 * to the Project resource itself are already gated behind MANAGE_PROJECTS).
 *
 * `mimes` validates the actual file content (via Symfony's MIME guesser), not just the
 * client-supplied extension/Content-Type header, matching StorePaymentRequest's `receipt` rule.
 */
class StoreProjectMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        return [
            'collection' => ['required', Rule::in(Project::MEDIA_COLLECTIONS)],
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}

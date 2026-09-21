<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /projects/{project}/boq — same "any active member can read" posture as
 * IndexProjectsRequest/IndexClientsRequest; BOQ mutations require Permissions::MANAGE_BOQ but
 * viewing a project's BOQ does not.
 */
class IndexBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }
}

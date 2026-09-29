<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/site-reports. Multipart body — optional `photos[]` files
 * (BRD S17 "Site Report: Work done, issues, decisions, photos, PDF"), attached at creation
 * time (a site report is authored once, at the end of a site visit — same "captured once"
 * reasoning as StoreTaskRequest's photos field).
 */
class StoreSiteReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_EXECUTION);
    }

    public function rules(): array
    {
        return [
            'report_date' => ['required', 'date'],
            'work_done' => ['required', 'string'],
            'issues' => ['nullable', 'string'],
            'decisions' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}

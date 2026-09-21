<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/boq/import — multipart upload, field name `file`. Row-level content
 * validation (required columns, numeric fields, category/room lookups) happens in
 * App\Services\Boq\BoqCsvImporter, not here — this only validates that something resembling a
 * CSV file was actually uploaded. 10MB cap is a generic upload-abuse safeguard, not a spec
 * requirement; BOQ CSVs are expected to be a few hundred rows at most.
 */
class ImportBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}

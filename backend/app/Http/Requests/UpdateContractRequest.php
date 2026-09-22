<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /contracts/{id}. Only start_date/end_date/terms_json are editable per
 * PROJECT_CONTEXT.md's Sprint 5 immutability boundary — contract_value and proposal_version_id
 * are permanently locked at creation.
 *
 * Judgment call (PROJECT_CONTEXT.md left this to backend-api-engineer, "your call"):
 * contract_value/proposal_version_id are REJECTED with 422 if present in the payload
 * (`prohibited`), not silently stripped. Reasoning: an extra/unknown field a client's UI
 * accidentally includes (e.g. echoing back the full contract object) is harmless noise and
 * would be fine to ignore, but these two fields are specifically the ones this sprint's whole
 * immutability boundary exists to protect — a request that includes them is either a client bug
 * serializing the read model back into the write payload (worth surfacing immediately, before
 * it becomes a silent habit that one day carries a real attempted override) or a genuine
 * attempt to change the commercial value/source snapshot, which must never happen quietly.
 * `prohibited` naturally routes through the existing ValidationException -> 422
 * `validation_failed` envelope (see bootstrap/app.php) with the offending field named in
 * `details`, so the caller gets clear, actionable feedback either way.
 */
class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            // No cross-field after_or_equal:start_date check: PATCH allows partial updates
            // (e.g. end_date alone while start_date already exists from a prior PATCH), and a
            // rule comparing only against co-present request input would misfire on that case
            // or silently miss it depending on which field is sent. Nothing in this sprint's
            // scope asks for that ordering guarantee at the API layer.
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'terms_json' => ['sometimes', 'nullable', 'array'],
            'contract_value' => ['prohibited'],
            'proposal_version_id' => ['prohibited'],
        ];
    }
}

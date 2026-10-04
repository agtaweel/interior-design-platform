<?php

namespace App\Http\Requests;

use App\Models\BoqTemplate;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoqTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'template_type' => ['sometimes', Rule::in([
                BoqTemplate::TYPE_FULL_FINISHING, BoqTemplate::TYPE_RENOVATION, BoqTemplate::TYPE_PARTIAL_FINISHING,
                BoqTemplate::TYPE_ROOM, BoqTemplate::TYPE_TRADE, BoqTemplate::TYPE_PACKAGE,
                BoqTemplate::TYPE_PREMIUM, BoqTemplate::TYPE_LUXURY, BoqTemplate::TYPE_CUSTOM,
            ])],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'finishing_level' => ['sometimes', 'nullable', Rule::in(['BASIC', 'STANDARD', 'PREMIUM', 'LUXURY'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

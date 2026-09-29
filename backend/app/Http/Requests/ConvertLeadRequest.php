<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /leads/{lead}/convert (BRD "CRM/Leads: conversion to client/project"). Always creates a
 * Client from the lead's name/phone/email/notes; a Project is created too only when
 * `create_project` is true, in which case `project_name` is required (falls back to
 * "{lead name} project" is deliberately NOT done — an explicit name avoids silently generating
 * a placeholder project name a designer didn't choose).
 */
class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_LEADS);
    }

    public function rules(): array
    {
        return [
            'create_project' => ['sometimes', 'boolean'],
            'project_name' => ['required_if:create_project,true', 'string', 'max:255'],
        ];
    }
}

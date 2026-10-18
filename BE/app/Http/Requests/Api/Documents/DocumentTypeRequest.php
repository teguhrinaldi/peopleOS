<?php

namespace App\Http\Requests\Api\Documents;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) or partially update (PATCH) a document type. */
class DocumentTypeRequest extends FormRequest
{
    private const FIELDS = [
        'code', 'name', 'description', 'requires_expiry', 'default_visible_to_employee',
        'allow_employee_documents', 'allow_company_documents', 'is_active', 'sort_order',
    ];

    private const FLAGS = ['requires_expiry', 'default_visible_to_employee', 'allow_employee_documents', 'allow_company_documents', 'is_active'];

    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $rules = [
            'code' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\- ]+$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $v = array_intersect_key($this->validated(), array_flip(self::FIELDS));
        foreach (self::FLAGS as $flag) {
            if (array_key_exists($flag, $v)) {
                $v[$flag] = $this->boolean($flag);
            }
        }

        return $v;
    }
}

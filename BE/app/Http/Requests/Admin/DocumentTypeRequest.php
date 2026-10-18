<?php

namespace App\Http\Requests\Admin;

/** Create or edit a document type (document.manage). The code is generated from the name on create and never changes. */
class DocumentTypeRequest extends AdminRequest
{
    private const FLAGS = ['requires_expiry', 'default_visible_to_employee', 'allow_employee_documents', 'allow_company_documents', 'is_active'];

    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['name', 'description', 'sort_order', 'code']);
        $flags = [];
        foreach (self::FLAGS as $flag) {
            $flags[$flag] = $this->boolean($flag);
        }
        $this->merge($flags);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'code' => [$this->isMethod('post') ? 'nullable' : 'prohibited', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\- ]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['boolean'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $v = $this->validated();
        if (($v['sort_order'] ?? null) === null) {
            unset($v['sort_order']);
        }

        return $v;
    }
}

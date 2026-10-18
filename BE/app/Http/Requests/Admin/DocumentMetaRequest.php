<?php

namespace App\Http\Requests\Admin;

/** Edit document metadata (the file itself is immutable) or give a reason for archiving. */
class DocumentMetaRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['title', 'document_type_id', 'expiry_date', 'notes', 'reason']);
        if ($this->isMethod('put')) {
            $this->merge(['visible_to_employee' => $this->boolean('visible_to_employee')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('admin.documents.archive')) {
            return ['reason' => ['nullable', 'string', 'max:500']];
        }

        return [
            'title' => ['required', 'string', 'max:150'],
            'document_type_id' => ['required', 'integer', 'min:1'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2200-12-31'],
            'visible_to_employee' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['document_type_id' => 'document type', 'expiry_date' => 'expiry date'];
    }
}

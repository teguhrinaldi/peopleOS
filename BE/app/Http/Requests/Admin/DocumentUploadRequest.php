<?php

namespace App\Http\Requests\Admin;

/**
 * Upload form. Mirrors the server rules the client-side check shows (size, extensions); the real file type is
 * decided from the content by DocumentFileInspector inside DocumentService, so a renamed file is still refused.
 */
class DocumentUploadRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['employee_id', 'document_type_id', 'title', 'expiry_date', 'notes']);
        $this->merge(['visible_to_employee' => $this->has('visible_to_employee') ? $this->boolean('visible_to_employee') : null]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $extensions = implode(',', array_keys((array) config('documents.allowed', [])));

        return [
            'file' => ['required', 'file', 'max:'.max(1, (int) config('documents.max_kb', 10240)), 'extensions:'.$extensions],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'document_type_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:150'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2200-12-31'],
            'visible_to_employee' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $kb = (int) config('documents.max_kb', 10240);

        return [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'The file is too large. The limit is '.round($kb / 1024, 1).' MB.',
            'file.extensions' => 'Only PDF, JPG, PNG, DOCX and XLSX files can be uploaded.',
            'file.uploaded' => 'The upload failed. The file may be larger than the server allows.',
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['document_type_id' => 'document type', 'expiry_date' => 'expiry date', 'employee_id' => 'employee'];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $v = $this->validated();

        return [
            'employee_id' => isset($v['employee_id']) ? (int) $v['employee_id'] : null,
            'document_type_id' => (int) $v['document_type_id'],
            'title' => (string) $v['title'],
            'expiry_date' => $v['expiry_date'] ?? null,
            'visible_to_employee' => $v['visible_to_employee'] ?? null,
            'notes' => $v['notes'] ?? null,
        ];
    }
}

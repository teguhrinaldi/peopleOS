<?php

namespace App\Http\Requests\Api\Documents;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Multipart upload. The FormRequest checks shape and size only; the file's real type is decided from its
 * content by DocumentFileInspector inside DocumentService (nothing is written before both pass).
 */
class DocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.max(1, (int) config('documents.max_kb', 10240))],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'document_type_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:150'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2200-12-31'],
            'visible_to_employee' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> explicit fields only (never the raw request) */
    public function payload(): array
    {
        $v = $this->validated();

        return [
            'employee_id' => isset($v['employee_id']) ? (int) $v['employee_id'] : null,
            'document_type_id' => (int) $v['document_type_id'],
            'title' => (string) $v['title'],
            'expiry_date' => $v['expiry_date'] ?? null,
            'visible_to_employee' => array_key_exists('visible_to_employee', $v) && $v['visible_to_employee'] !== null ? $this->boolean('visible_to_employee') : null,
            'notes' => $v['notes'] ?? null,
        ];
    }
}

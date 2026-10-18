<?php

namespace App\Http\Requests\Api\Documents;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH semantics: only the keys that are present change. The file itself is immutable. */
class DocumentUpdateRequest extends FormRequest
{
    private const FIELDS = ['title', 'document_type_id', 'expiry_date', 'visible_to_employee', 'notes'];

    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'document_type_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2200-12-31'],
            'visible_to_employee' => ['sometimes', 'required', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $v = array_intersect_key($this->validated(), array_flip(self::FIELDS));

        if (array_key_exists('visible_to_employee', $v)) {
            $v['visible_to_employee'] = $this->boolean('visible_to_employee');
        }
        if (array_key_exists('document_type_id', $v)) {
            $v['document_type_id'] = (int) $v['document_type_id'];
        }

        return $v;
    }
}

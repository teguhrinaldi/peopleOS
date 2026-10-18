<?php

namespace App\Http\Requests\Api\Documents;

use App\Enums\DocumentStatus;
use App\Services\Documents\DocumentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission('document.view', 'document.manage', 'document.view_own');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $this->merge(['status' => strtoupper((string) $this->query('status'))]);
        }
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'in:mine,all'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'document_type_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(DocumentStatus::values())],
            'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'company_only' => ['nullable', 'boolean'],
            'employee_only' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(DocumentQuery::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'employee_id' => isset($v['employee_id']) ? (int) $v['employee_id'] : null,
            'document_type_id' => isset($v['document_type_id']) ? (int) $v['document_type_id'] : null,
            'status' => $v['status'] ?? null,
            'expiring_within_days' => isset($v['expiring_within_days']) ? (int) $v['expiring_within_days'] : null,
            'company_only' => $this->boolean('company_only'),
            'employee_only' => $this->boolean('employee_only'),
            'q' => $v['q'] ?? null,
            'sort' => $v['sort'] ?? 'recent',
        ];
    }

    public function scope(): string
    {
        return $this->validated()['scope'] ?? 'mine';
    }
}

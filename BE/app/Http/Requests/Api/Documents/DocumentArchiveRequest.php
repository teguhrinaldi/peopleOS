<?php

namespace App\Http\Requests\Api\Documents;

use Illuminate\Foundation\Http\FormRequest;

/** Archive / restore / delete: permission first (before the record is even loaded), then the policy. */
class DocumentArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.manage');
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        return $this->validated()['reason'] ?? null;
    }
}

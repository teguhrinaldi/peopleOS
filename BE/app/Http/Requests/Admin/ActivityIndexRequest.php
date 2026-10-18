<?php

namespace App\Http\Requests\Admin;

class ActivityIndexRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('activity.view');
    }

    protected function prepareForValidation(): void
    {
        $this->normalize(['actor_id', 'action', 'entity_type', 'from', 'to']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:80'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['actor_id', 'action', 'entity_type', 'from', 'to']);
    }
}

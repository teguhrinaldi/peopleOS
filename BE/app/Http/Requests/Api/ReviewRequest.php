<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Decision payload for approve / reject. Authorization is decided by the policy on the target record. */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rejecting = $this->routeIs('*.reject');

        return [
            'note' => [$rejecting ? 'required' : 'nullable', 'string', 'max:500'],
            'approved_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}

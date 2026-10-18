<?php

namespace App\Services\Admin\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Validation\ValidationException;

/**
 * Turns a domain ApiException into something a form can show. Field-level problems (a VALIDATION_ERROR
 * with details, or a code listed in $fieldMap) become ValidationException errors under the right input;
 * everything else is rethrown and shown as a friendly flash message by the global web handler.
 */
trait TranslatesDomainErrors
{
    /** @param array<string, string> $fieldMap domain error code => form field */
    protected function domain(callable $work, array $fieldMap = [], ?string $forceField = null): mixed
    {
        try {
            return $work();
        } catch (ApiException $e) {
            if ($e->status >= 500 || in_array($e->status, [401, 403], true)) {
                throw $e;
            }
            if ($forceField !== null) {
                throw ValidationException::withMessages([$forceField => $e->getMessage()]);
            }
            if (isset($fieldMap[$e->errorCode])) {
                throw ValidationException::withMessages([$fieldMap[$e->errorCode] => $e->getMessage()]);
            }
            if ($e->status === 422 && $e->details !== [] && is_string(array_key_first($e->details))) {
                throw ValidationException::withMessages($e->details);
            }
            throw $e;
        }
    }
}

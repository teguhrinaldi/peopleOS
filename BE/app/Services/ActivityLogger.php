<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail. Sensitive keys are stripped before anything is stored.
 */
class ActivityLogger
{
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'token', 'access_token', 'plain_text_token',
        'remember_token', 'api_token', 'secret', 'selfie_path',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        Model|string $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        User|int|null $actor = null,
    ): Activity {
        $actorId = $actor instanceof User ? $actor->getKey() : ($actor ?? Auth::id());

        return Activity::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity->getMorphClass() : $entity,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : null,
            'description' => $description,
            'old_values' => $old !== null ? $this->redact($old) : null,
            'new_values' => $new !== null ? $this->redact($new) : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent() ? mb_substr($this->request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * Pending changes as [old, new]. Call BEFORE save(): afterwards the original is already overwritten.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(Model $model): array
    {
        $new = $model->getDirty();
        unset($new['updated_at']);
        $old = array_intersect_key($model->getRawOriginal(), $new);

        return [$old, $new];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}

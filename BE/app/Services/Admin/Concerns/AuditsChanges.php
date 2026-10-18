<?php

namespace App\Services\Admin\Concerns;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Save / delete a reference-data model and write the matching audit row.
 * The using class must expose `$this->activity` (ActivityLogger).
 *
 * @property-read ActivityLogger $activity
 */
trait AuditsChanges
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields  the only attributes taken from $data
     * @param  array<string, mixed>  $extraNew  values added to the audit row (e.g. derived or child data)
     */
    protected function persist(Model $model, array $data, array $fields, string $entity, User $actor, array $extraNew = [], array $extraOld = []): Model
    {
        $model->fill(array_intersect_key($data, array_flip($fields)));
        $creating = ! $model->exists;

        // diff() reads the dirty values, so it must run before save().
        [$old, $new] = $creating ? [null, null] : $this->activity->diff($model);
        $model->save();

        if ($creating) {
            $this->activity->log("{$entity}.created", $model, null, $model->only($fields) + $extraNew, actor: $actor);
        } elseif ($new !== [] || $extraNew !== []) {
            $this->activity->log("{$entity}.updated", $model, $old + $extraOld, $new + $extraNew, actor: $actor);
        }

        return $model;
    }

    protected function remove(Model $model, string $entity, User $actor, array $snapshot = []): void
    {
        DB::transaction(function () use ($model, $entity, $actor, $snapshot) {
            $this->activity->log("{$entity}.deleted", $model, $snapshot ?: $model->only(['code', 'name']), null, actor: $actor);
            $model->delete();
        });
    }
}

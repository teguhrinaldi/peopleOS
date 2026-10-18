<?php

namespace App\Services\Admin;

use App\Models\Activity;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Read-only browser for the append-only audit trail. Date filters are company-local days. */
class ActivityLog
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityPresenter $presenter,
    ) {}

    /** @param array<string, mixed> $f actor_id, action, entity_type, from, to */
    public function paginate(array $f, int $perPage = 25): LengthAwarePaginator
    {
        $page = Activity::query()
            ->with('actor')
            ->when($f['actor_id'] ?? null, fn (Builder $q, $id) => $q->where('actor_id', $id))
            ->when($f['action'] ?? null, fn (Builder $q, $action) => $q->where('action', $action))
            ->when($f['entity_type'] ?? null, fn (Builder $q, $type) => $q->where('entity_type', $type))
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', $this->context->localToUtc($d, '00:00:00')))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<', $this->context->localToUtc($d, '00:00:00')->addDay()))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $page->setCollection($this->presenter->present($page->getCollection()));

        return $page;
    }

    /** @return array{actors: array<int, string>, actions: array<string, string>, entities: array<string, string>} */
    public function filterOptions(): array
    {
        $actions = Activity::query()->distinct()->orderBy('action')->pluck('action');
        $entities = Activity::query()->distinct()->orderBy('entity_type')->pluck('entity_type');

        return [
            'actors' => User::withTrashed()->whereIn('id', Activity::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
                ->orderBy('name')->pluck('name', 'id')->all(),
            'actions' => $actions->mapWithKeys(fn ($a) => [$a => $a])->all(),
            'entities' => $entities->mapWithKeys(fn ($t) => [$t => ActivityPresenter::entityLabel($t)])->all(),
        ];
    }
}

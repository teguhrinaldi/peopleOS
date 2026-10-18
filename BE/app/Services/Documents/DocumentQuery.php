<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Support\CompanyContext;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped document listing shared by the API and the web admin.
 *
 * The base query is ALWAYS derived from the caller's permissions (DocumentPolicy rules expressed in SQL);
 * filters only ever narrow it, so an employee_id or id from the client can never widen access.
 * Status filters are evaluated from expiry dates against company-timezone today, exactly like
 * DocumentService::statusFor(), so a list and a detail view can never disagree between job runs.
 */
class DocumentQuery
{
    public const SORTS = ['recent', 'expiry', 'title'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly Settings $settings,
        private readonly DocumentPolicy $policy,
    ) {}

    /** Every non-deleted document of the company (for callers holding document.view / document.manage). */
    public function all(): Builder
    {
        return Document::query()->where('documents.company_id', $this->context->id());
    }

    /** Self-service set: own documents visible to the employee + company-wide documents visible to all. */
    public function selfService(User $user): Builder
    {
        $query = $this->all();
        $me = $user->employee;

        if (! $me || ! $user->is_active || ! $user->hasPermission('document.view_own')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('documents.visible_to_employee', true)
            ->whereNull('documents.archived_at')
            ->where(fn (Builder $w) => $w->where('documents.employee_id', $me->id)->orWhereNull('documents.employee_id'));
    }

    /** What this user may see at all: everything with view/manage, otherwise the self-service set. */
    public function visibleTo(User $user): Builder
    {
        return $this->policy->viewAll($user) ? $this->all() : $this->selfService($user);
    }

    /** A single document the user may see, or null (callers answer 404 either way: no existence leak). */
    public function findVisible(User $user, int $id): ?Document
    {
        $document = $this->visibleTo($user)->whereKey($id)->first();

        return $document && $this->policy->view($user, $document) ? $document : null;
    }

    /**
     * @param  array{employee_id?: int|null, document_type_id?: int|null, status?: string|null,
     *               expiring_within_days?: int|null, q?: string|null, company_only?: bool|null,
     *               employee_only?: bool|null, sort?: string|null}  $filters
     */
    public function filter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['employee_id'])) {
            $query->where('documents.employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['document_type_id'])) {
            $query->where('documents.document_type_id', (int) $filters['document_type_id']);
        }
        if (! empty($filters['company_only'])) {
            $query->whereNull('documents.employee_id');
        } elseif (! empty($filters['employee_only'])) {
            $query->whereNotNull('documents.employee_id');
        }
        if (! empty($filters['status'])) {
            $this->whereStatus($query, DocumentStatus::from(strtoupper((string) $filters['status'])));
        }
        if (isset($filters['expiring_within_days']) && $filters['expiring_within_days'] !== null && $filters['expiring_within_days'] !== '') {
            $this->whereExpiringWithin($query, (int) $filters['expiring_within_days']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('documents.title', 'like', $term)->orWhere('documents.original_name', 'like', $term));
        }

        return match ($filters['sort'] ?? 'recent') {
            'expiry' => $query->orderByRaw('documents.expiry_date IS NULL')->orderBy('documents.expiry_date')->orderByDesc('documents.id'),
            'title' => $query->orderBy('documents.title')->orderByDesc('documents.id'),
            default => $query->orderByDesc('documents.uploaded_at')->orderByDesc('documents.id'),
        };
    }

    /** Status predicate computed from dates (same rules as DocumentStatusCalculator). */
    public function whereStatus(Builder $query, DocumentStatus $status): Builder
    {
        [$today, $horizon] = $this->window();

        return match ($status) {
            DocumentStatus::Archived => $query->whereNotNull('documents.archived_at'),
            DocumentStatus::Expired => $query->whereNull('documents.archived_at')->where('documents.expiry_date', '<', $today),
            DocumentStatus::Expiring => $query->whereNull('documents.archived_at')->whereBetween('documents.expiry_date', [$today, $horizon]),
            DocumentStatus::Active => $query->whereNull('documents.archived_at')
                ->where(fn (Builder $w) => $w->whereNull('documents.expiry_date')->orWhere('documents.expiry_date', '>', $horizon)),
        };
    }

    /** Not archived and expiring between today and today + N days (inclusive). Expired ones excluded. */
    public function whereExpiringWithin(Builder $query, int $days): Builder
    {
        $today = $this->context->today();

        return $query->whereNull('documents.archived_at')
            ->whereBetween('documents.expiry_date', [$today->toDateString(), $today->addDays(max(0, $days))->toDateString()]);
    }

    /** @return array{0: string, 1: string} today and the last day that still counts as "expiring" */
    public function window(): array
    {
        $today = $this->context->today();

        return [$today->toDateString(), $today->addDays(max(0, $this->settings->int('documents.expiring_days')))->toDateString()];
    }
}

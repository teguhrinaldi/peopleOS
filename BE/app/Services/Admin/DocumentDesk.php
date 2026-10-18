<?php

namespace App\Services\Admin;

use App\Enums\DocumentStatus;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentQuery;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentTypeService;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Read models for the Documents pages. Visibility always comes from DocumentQuery (permission-derived, never from the client). */
class DocumentDesk
{
    public const STATUSES = ['ACTIVE' => 'Active', 'EXPIRING' => 'Expiring soon', 'EXPIRED' => 'Expired', 'ARCHIVED' => 'Archived'];

    /** Extensions the browser may show inline; everything else downloads. */
    public const PREVIEWABLE = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private readonly DocumentQuery $query,
        private readonly DocumentService $service,
        private readonly DocumentTypeService $types,
        private readonly ActivityPresenter $presenter,
        private readonly CompanyContext $context,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(User $viewer, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $filters['sort'] = $filters['sort'] ?? 'recent';

        return $this->query->filter($this->query->visibleTo($viewer)->with(['type:id,code,name', 'employee:id,full_name,employee_number']), $filters)
            ->paginate($perPage)->withQueryString();
    }

    /** @return array{types: array<int, string>, employees: array<int, string>} */
    public function filterOptions(User $viewer): array
    {
        $ids = $this->query->visibleTo($viewer)->whereNotNull('documents.employee_id')->distinct()->pluck('documents.employee_id');

        return [
            'types' => $this->types->list(true)->pluck('name', 'id')->all(),
            'employees' => Employee::query()->where('company_id', $this->context->id())->whereIn('id', $ids)->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])
                ->mapWithKeys(fn ($e) => [$e->id => $e->full_name.' ('.$e->employee_number.')'])->all(),
        ];
    }

    /** Types an upload may use: active only, grouped flags tell the form what each allows. */
    public function uploadTypes(): Collection
    {
        return $this->types->list(false);
    }

    /** Employees an upload may target (document.manage = whole company). */
    public function employeeOptions(): array
    {
        return Employee::query()->where('company_id', $this->context->id())->whereNotIn('status', ['TERMINATED'])->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])
            ->mapWithKeys(fn ($e) => [$e->id => $e->full_name.' ('.$e->employee_number.')'])->all();
    }

    public function status(Document $document): DocumentStatus
    {
        return $this->service->statusFor($document);
    }

    public function daysLeft(Document $document): ?int
    {
        return $this->service->daysUntilExpiry($document);
    }

    public function expiringDays(): int
    {
        return $this->service->expiringDays();
    }

    /** A document the viewer may see, or null (callers answer 404: no existence leak). */
    public function findVisible(User $viewer, int $id): ?Document
    {
        return $this->query->findVisible($viewer, $id);
    }

    /** A document of this company for management actions (permission is checked by the route and the policy). */
    public function findManaged(int $id): ?Document
    {
        return $this->query->all()->whereKey($id)->first();
    }

    /** Audit trail of one document, newest first. */
    public function trail(Document $document): Collection
    {
        return $this->presenter->present(
            Activity::query()->with('actor')->where('entity_type', $document->getMorphClass())->where('entity_id', $document->id)->latest('id')->limit(50)->get(),
        );
    }

    /** Counts for the dashboard tile; null when the viewer cannot see all documents. */
    public function expiryCounts(User $viewer): ?array
    {
        if (! $viewer->hasPermission('document.view')) {
            return null;
        }
        $base = fn () => $this->query->all();

        return [
            'expiring' => $this->query->whereStatus($base(), DocumentStatus::Expiring)->count(),
            'expired' => $this->query->whereStatus($base(), DocumentStatus::Expired)->count(),
            'days' => $this->service->expiringDays(),
        ];
    }

    /** @return Collection<int, Document> the documents of one employee (their detail tab) */
    public function forEmployee(User $viewer, Employee $employee): Collection
    {
        return $this->query->filter($this->query->visibleTo($viewer)->with('type:id,code,name'), ['employee_id' => $employee->id])->limit(100)->get();
    }
}

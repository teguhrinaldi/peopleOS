<?php

namespace App\Services\Reimbursements;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Configurable catalogue. Categories are never deleted once used: retire with is_active = false. */
class ExpenseCategoryService
{
    /** [code, name, receipt_required, per_claim, per_month, max_age_days] (amounts in the company currency) */
    public const DEFAULTS = [
        ['TRANSPORT', 'Transport', true, 1500000, 3000000, 60],
        ['MEALS', 'Meals & Entertainment', true, 500000, 2000000, 30],
        ['ACCOMMODATION', 'Accommodation', true, 3000000, 10000000, 60],
        ['OFFICE_SUPPLIES', 'Office Supplies', true, 1000000, 2000000, 45],
        ['COMMUNICATION', 'Phone & Internet', true, 500000, 1000000, 60],
        ['PARKING_TOLL', 'Parking & Toll', false, 100000, 500000, 30],
    ];

    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    /** @return Collection<int, ExpenseCategory> */
    public function list(bool $includeInactive = false): Collection
    {
        return ExpenseCategory::query()->where('company_id', $this->context->id())
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Idempotent: creates missing defaults, never overwrites what an administrator edited. */
    public function installDefaults(): int
    {
        $created = 0;
        foreach (self::DEFAULTS as $i => [$code, $name, $receipt, $claim, $month, $age]) {
            $category = ExpenseCategory::query()->firstOrCreate(
                ['company_id' => $this->context->id(), 'code' => $code],
                ['name' => $name, 'receipt_required' => $receipt, 'max_amount_per_claim' => $claim, 'max_amount_per_month' => $month,
                    'max_age_days' => $age, 'is_active' => true, 'sort_order' => ($i + 1) * 10],
            );
            $created += $category->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ExpenseCategory
    {
        $data = $this->clean($data);
        try {
            $category = ExpenseCategory::query()->create($data + ['company_id' => $this->context->id()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This code is already used by another category.']);
        }
        $this->activity->log('expense_category.created', $category, null, $category->only(array_keys($data)), actor: $actor);

        return $category;
    }

    /** @param array<string, mixed> $data keys present = fields to change */
    public function update(User $actor, ExpenseCategory $category, array $data): ExpenseCategory
    {
        $category->fill($this->clean($data, updating: true));
        if (! $category->isDirty()) {
            return $category;
        }
        [$old, $new] = $this->activity->diff($category);
        try {
            $category->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This code is already used by another category.']);
        }
        $this->activity->log('expense_category.updated', $category, $old, $new, actor: $actor);

        return $category;
    }

    public function find(int $id): ?ExpenseCategory
    {
        return ExpenseCategory::query()->where('company_id', $this->context->id())->find($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function clean(array $data, bool $updating = false): array
    {
        $out = array_intersect_key($data, array_flip(['code', 'name', 'description', 'is_active', 'receipt_required',
            'max_amount_per_claim', 'max_amount_per_month', 'max_age_days', 'sort_order']));
        if (isset($out['code'])) {
            $out['code'] = strtoupper(trim((string) $out['code']));
        }
        foreach (['max_amount_per_claim', 'max_amount_per_month'] as $k) {
            if (array_key_exists($k, $out) && $out[$k] !== null && bccomp((string) $out[$k], '0', 2) <= 0) {
                throw ValidationException::withMessages([$k => 'The limit must be greater than zero (leave empty for no limit).']);
            }
        }

        return $out;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ExpenseResources;
use App\Services\Reimbursements\ExpenseCategoryService;
use App\Services\Reimbursements\ExpensePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Categories (read: anyone who can use reimbursements; write: expense.manage) and the company policy. */
class ExpenseSetupController extends ApiController
{
    private const CATEGORY_RULES = [
        'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'], 'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255'],
        'is_active' => ['nullable', 'boolean'], 'receipt_required' => ['nullable', 'boolean'],
        'max_amount_per_claim' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'], 'max_amount_per_month' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
        'max_age_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
    ];

    public function __construct(private readonly ExpenseCategoryService $categories, private readonly ExpensePolicyService $policy) {}

    public function categories(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasAnyPermission('expense.request', 'expense.view', 'expense.view_team', 'expense.approve', 'expense.approve_team', 'expense.pay', 'expense.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have access to reimbursements.', 403);
        }
        $all = $request->boolean('include_inactive') && $user->hasPermission('expense.manage');

        return $this->ok($this->categories->list($all)->map(fn ($c) => ExpenseResources::category($c, $user->hasPermission('expense.manage')))->values());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->manage($request);

        return $this->ok(ExpenseResources::category($this->categories->create($request->user(), $request->validate(self::CATEGORY_RULES)), true), 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $this->manage($request);
        $category = $this->categories->find($id) ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        $rules = collect(self::CATEGORY_RULES)->map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r))->all();

        return $this->ok(ExpenseResources::category($this->categories->update($request->user(), $category, $request->validate($rules)), true));
    }

    public function policy(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission('expense.request', 'expense.view', 'expense.view_team', 'expense.approve', 'expense.approve_team', 'expense.pay', 'expense.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have access to reimbursements.', 403);
        }

        return $this->ok(ExpenseResources::policy($this->policy->current(), $this->policy->maxReceiptKb()));
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'approval_route' => ['sometimes', 'in:MANAGER_OR_HR,HR_ONLY'], 'payment_route' => ['sometimes', 'in:SEPARATE,PAYROLL'],
            'max_amount_per_claim' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999999'], 'max_amount_per_month' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'max_receipt_kb' => ['sometimes', 'integer', 'min:50', 'max:102400'],
        ]);

        return $this->ok(ExpenseResources::policy($this->policy->update($request->user(), $data), $this->policy->maxReceiptKb()));
    }

    private function manage(Request $request): void
    {
        if (! $request->user()->hasPermission('expense.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to configure reimbursements.', 403);
        }
    }
}

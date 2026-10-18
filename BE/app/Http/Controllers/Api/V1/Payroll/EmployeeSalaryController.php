<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Payroll\EmployeeSalaryChangeRequest;
use App\Http\Resources\PayrollResources;
use App\Models\Employee;
use App\Services\AccessScope;
use App\Services\Payroll\EmployeeSalaryService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET/POST /employees/{id}/salary - effective-dated history and new changes (salary.view / salary.manage). */
class EmployeeSalaryController extends ApiController
{
    public function __construct(
        private readonly EmployeeSalaryService $salaries,
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
    ) {}

    public function show(Request $request, int $employee): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $model = $this->resolve($request, $employee, 'salary.view');
        $date = $request->query('date', $this->context->today()->toDateString());

        return $this->ok([
            'employee' => ['id' => $model->id, 'employee_number' => $model->employee_number, 'full_name' => $model->full_name],
            'date' => $date,
            'current' => $this->salaries->valuesOn($model, $date)->map(fn ($r) => PayrollResources::salaryValue($r))->values(),
            'history' => $this->salaries->history($model)->map(fn ($r) => PayrollResources::salaryValue($r))->values(),
        ]);
    }

    public function store(EmployeeSalaryChangeRequest $request, int $employee): JsonResponse
    {
        $model = $this->resolve($request, $employee, 'salary.manage');
        $result = $this->salaries->change(
            $request->user(), $model, $request->validated('effective_from'), $request->changes(), $request->validated('reason'),
        );

        return $this->ok([
            'created' => collect($result['created'])->map(fn ($r) => PayrollResources::salaryValue($r->load('component')))->values(),
            'ended' => $result['ended'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    private function resolve(Request $request, int $id, string $permission): Employee
    {
        $user = $request->user();
        if (! $user->hasAnyPermission($permission, 'salary.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }
        $employee = $this->scope->employees($user, $user->hasPermission($permission) ? $permission : 'salary.manage', null, false)
            ->withTrashed()->find($id);
        if (! $employee) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $employee;
    }
}

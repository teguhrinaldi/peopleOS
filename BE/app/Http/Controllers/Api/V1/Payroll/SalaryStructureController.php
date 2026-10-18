<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Payroll\AssignStructureRequest;
use App\Http\Requests\Api\Payroll\SalaryStructureRequest;
use App\Http\Resources\PayrollResources;
use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Services\Payroll\SalaryStructureService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryStructureController extends ApiController
{
    public function __construct(private readonly SalaryStructureService $service, private readonly CompanyContext $context) {}

    public function index(): JsonResponse
    {
        $structures = SalaryStructure::query()->where('company_id', $this->context->id())
            ->with('lines.component')->orderBy('name')->get();

        return $this->ok($structures->map(fn ($s) => PayrollResources::structure($s))->values());
    }

    public function show(SalaryStructure $salaryStructure): JsonResponse
    {
        return $this->ok(PayrollResources::structure($this->own($salaryStructure)->load('lines.component')));
    }

    public function store(SalaryStructureRequest $request): JsonResponse
    {
        $structure = $this->service->save($request->user(), null, $request->fields(), $request->components());

        return $this->ok(PayrollResources::structure($structure), 201);
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $salaryStructure): JsonResponse
    {
        $structure = $this->service->save($request->user(), $this->own($salaryStructure), $request->fields(), $request->components());

        return $this->ok(PayrollResources::structure($structure));
    }

    public function destroy(Request $request, SalaryStructure $salaryStructure): JsonResponse
    {
        if (! $request->user()->hasPermission('salary.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }
        $this->service->delete($request->user(), $this->own($salaryStructure));

        return $this->ok(null);
    }

    /** Copy the structure's values into one employee's effective-dated salary from a date. */
    public function assign(AssignStructureRequest $request, SalaryStructure $salaryStructure): JsonResponse
    {
        $employee = Employee::query()->where('company_id', $this->context->id())->find($request->integer('employee_id'));
        if (! $employee) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $result = $this->service->assign(
            $request->user(),
            $this->own($salaryStructure),
            $employee,
            $request->validated('effective_from'),
            $request->validated('reason'),
            $request->overrides(),
        );

        return $this->ok([
            'created' => collect($result['created'])->map(fn ($r) => PayrollResources::salaryValue($r->load('component')))->values(),
            'ended' => $result['ended'],
            'warnings' => $result['warnings'],
        ], 201);
    }

    private function own(SalaryStructure $structure): SalaryStructure
    {
        if ($structure->company_id !== $this->context->id()) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $structure;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Payroll\SalaryComponentRequest;
use App\Http\Resources\PayrollResources;
use App\Models\SalaryComponent;
use App\Services\Payroll\SalaryComponentService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Salary component catalog. Read: salary.view (or salary.manage). Write: salary.manage (route middleware + FormRequest). */
class SalaryComponentController extends ApiController
{
    public function __construct(private readonly SalaryComponentService $service, private readonly CompanyContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['nullable', 'boolean'], 'kind' => ['nullable', 'in:EARNING,DEDUCTION']]);

        $query = SalaryComponent::query()->where('company_id', $this->context->id())->ordered();
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        return $this->ok($query->get()->map(fn ($c) => PayrollResources::component($c))->values());
    }

    public function show(SalaryComponent $salaryComponent): JsonResponse
    {
        return $this->ok(PayrollResources::component($this->own($salaryComponent)));
    }

    public function store(SalaryComponentRequest $request): JsonResponse
    {
        return $this->ok(PayrollResources::component($this->service->create($request->user(), $request->fields())), 201);
    }

    public function update(SalaryComponentRequest $request, SalaryComponent $salaryComponent): JsonResponse
    {
        return $this->ok(PayrollResources::component($this->service->update($request->user(), $this->own($salaryComponent), $request->fields())));
    }

    public function destroy(Request $request, SalaryComponent $salaryComponent): JsonResponse
    {
        if (! $request->user()->hasPermission('salary.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }
        $this->service->delete($request->user(), $this->own($salaryComponent));

        return $this->ok(null);
    }

    private function own(SalaryComponent $component): SalaryComponent
    {
        if ($component->company_id !== $this->context->id()) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $component;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Overtime;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\OvertimeResources;
use App\Models\OvertimeRule;
use App\Services\Overtime\OvertimeRuleService;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Overtime rules. Read: overtime.manage | payroll.manage | payroll.view. Write: overtime.manage | payroll.manage. */
class OvertimeRuleController extends ApiController
{
    public function __construct(private readonly OvertimeRuleService $service, private readonly CompanyContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['active' => ['nullable', 'boolean']]);
        $query = OvertimeRule::query()->where('company_id', $this->context->id())
            ->orderByDesc('is_active')->orderByDesc('effective_from')->orderBy('id');
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return $this->ok($query->get()->map(fn (OvertimeRule $r) => OvertimeResources::rule($r))->values());
    }

    public function show(OvertimeRule $overtimeRule): JsonResponse
    {
        return $this->ok(OvertimeResources::rule($this->own($overtimeRule)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->ok(OvertimeResources::rule($this->service->create($request->user(), $request->all())), 201);
    }

    public function update(Request $request, OvertimeRule $overtimeRule): JsonResponse
    {
        return $this->ok(OvertimeResources::rule($this->service->update($request->user(), $this->own($overtimeRule), $request->all())));
    }

    public function destroy(Request $request, OvertimeRule $overtimeRule): JsonResponse
    {
        $this->service->delete($request->user(), $this->own($overtimeRule));

        return $this->ok(null);
    }

    private function own(OvertimeRule $rule): OvertimeRule
    {
        if ($rule->company_id !== $this->context->id()) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $rule;
    }
}

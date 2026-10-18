<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PayrollResources;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only access to runs, items and lines (payroll.view). Nothing here writes. */
class PayrollRunController extends ApiController
{
    public function show(PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('view', $payrollRun->period);

        return $this->ok(PayrollResources::run($payrollRun->load(['generatedBy', 'submittedBy', 'approvedBy', 'finalizedBy', 'paidBy'])));
    }

    public function items(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('view', $payrollRun->period);
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'negative' => ['nullable', 'boolean'], 'employee_id' => ['nullable', 'integer']]);

        $query = PayrollItem::query()->where('payroll_run_id', $payrollRun->id);
        if ($request->filled('q')) {
            $q = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->query('q')).'%';
            $query->where(fn ($w) => $w->where('employee_name', 'like', $q)->orWhere('employee_number', 'like', $q));
        }
        if ($request->has('negative')) {
            $query->where('has_negative_net', $request->boolean('negative'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        return $this->paginated(
            $query->orderBy('employee_number')->orderBy('id')->paginate($this->perPage($request, 50)),
            fn ($i) => PayrollResources::item($i),
        );
    }

    public function item(PayrollItem $payrollItem): JsonResponse
    {
        $run = $payrollItem->run;
        if (! $run) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        $this->authorize('view', $run->period);

        return $this->ok(PayrollResources::item($payrollItem->load('lines'), true));
    }
}

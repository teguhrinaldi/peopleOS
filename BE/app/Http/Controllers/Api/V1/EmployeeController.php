<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only employee access for the API. Creating/editing people is a Web Admin job.
 * Every query goes through AccessScope, so the caller only ever sees what their permissions allow.
 */
class EmployeeController extends ApiController
{
    private const WITH = [
        'currentAssignment.department', 'currentAssignment.position', 'currentAssignment.branch',
        'currentAssignment.manager', 'currentAssignment.workLocation', 'currentAssignment.shift',
    ];

    public function __construct(private readonly AccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:PENDING,ACTIVE,INACTIVE,TERMINATED'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $query = $this->scope->employees($request->user(), 'employee.view', 'employee.view_team')->with(self::WITH);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")
                ->orWhere('employee_number', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('department_id')) {
            $department = (int) $request->query('department_id');
            $query->whereHas('currentAssignment', fn ($a) => $a->where('department_id', $department));
        }

        return $this->paginated(
            $query->orderBy('full_name')->paginate($this->perPage($request)),
            fn (Employee $e) => (new EmployeeResource($e))->resolve(),
        );
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok((new EmployeeResource($this->employee($request)->load(self::WITH)))->resolve());
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return $this->ok((new EmployeeResource($employee->load(self::WITH)))->resolve());
    }
}

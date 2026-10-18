<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use App\Models\Shift;
use App\Models\WorkLocation;
use App\Support\CompanyContext;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only reference data for pickers. Requires organization.view (shifts: attendance.view). */
class OrganizationController extends ApiController
{
    public function __construct(private readonly CompanyContext $context) {}

    public function company(Request $request): JsonResponse
    {
        $this->require($request, 'organization.view');
        $c = $this->context->company();

        return $this->ok(['id' => $c->id, 'name' => $c->name, 'timezone' => $c->timezone, 'address' => $c->address]);
    }

    public function branches(Request $request): JsonResponse
    {
        $this->require($request, 'organization.view');

        return $this->ok(Branch::query()->where('company_id', $this->context->id())->orderBy('name')->get()
            ->map(fn ($b) => $b->only(['id', 'code', 'name', 'city', 'is_active'])));
    }

    public function departments(Request $request): JsonResponse
    {
        $this->require($request, 'organization.view');

        return $this->ok(Department::query()->where('company_id', $this->context->id())->orderBy('name')->get()
            ->map(fn ($d) => $d->only(['id', 'code', 'name', 'branch_id', 'parent_id', 'is_active'])));
    }

    public function positions(Request $request): JsonResponse
    {
        $this->require($request, 'organization.view');

        return $this->ok(Position::query()->where('company_id', $this->context->id())->orderBy('name')->get()
            ->map(fn ($p) => $p->only(['id', 'code', 'name', 'level', 'is_active'])));
    }

    public function workLocations(Request $request): JsonResponse
    {
        $this->require($request, 'organization.view');

        return $this->ok(WorkLocation::query()->where('company_id', $this->context->id())->orderBy('name')->get()
            ->map(fn ($l) => $l->only(['id', 'code', 'name', 'address', 'latitude', 'longitude', 'radius_meters', 'branch_id', 'is_active'])));
    }

    public function shifts(Request $request): JsonResponse
    {
        $this->require($request, 'attendance.view');

        return $this->ok(Shift::query()->where('company_id', $this->context->id())->with('breaks')->orderBy('name')->get()
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'start_time' => Fmt::hm($s->start_time),
                'end_time' => Fmt::hm($s->end_time),
                'crosses_midnight' => $s->crosses_midnight,
                'grace_late_minutes' => $s->grace_late_minutes,
                'grace_early_leave_minutes' => $s->grace_early_leave_minutes,
                'break_minutes' => $s->break_minutes,
                'working_minutes' => $s->working_minutes,
                'is_active' => $s->is_active,
                'breaks' => $s->breaks->map(fn ($b) => [
                    'name' => $b->name, 'start_time' => Fmt::hm($b->start_time), 'end_time' => Fmt::hm($b->end_time),
                    'duration_minutes' => $b->duration_minutes, 'is_paid' => $b->is_paid,
                ])->values(),
            ]));
    }

    private function require(Request $request, string $permission): void
    {
        if (! $request->user()->hasPermission($permission)) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to access this resource.', 403);
        }
    }
}

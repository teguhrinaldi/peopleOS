<?php

namespace App\Services\Admin;

use App\Enums\LeaveStatus;
use App\Exceptions\ApiException;
use App\Models\Activity;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveCalendar;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Read models for the web Leave pages. Visibility comes from AccessScope and LeaveRequestPolicy;
 * every decision is made by App\Services\Leave\LeaveService (never here).
 *
 *  - review queue : PENDING requests the viewer may decide (leave.approve / leave.approve_team), never their own
 *  - history      : decided and live requests the viewer may see (leave.view / view_team or the approve scope)
 *  Drafts are private to their author and never listed here.
 */
class LeaveDesk
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly CompanyContext $context,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveCalendar $calendar,
    ) {}

    /** @param array<string, mixed> $f status, leave_type_id, department_id, from, to, employee_id */
    public function paginate(User $user, string $tab, array $f): LengthAwarePaginator
    {
        $people = $tab === 'pending' ? $this->decidable($user) : $this->visible($user);

        return LeaveRequest::query()
            ->whereIn('leave_requests.employee_id', $this->notMine($user, $people))
            ->where('leave_requests.status', '!=', LeaveStatus::Draft->value)
            ->when($tab === 'pending', fn (Builder $q) => $q->where('leave_requests.status', LeaveStatus::Pending->value))
            ->when($tab !== 'pending' && ! empty($f['status']), fn (Builder $q) => $q->where('leave_requests.status', $f['status']))
            ->when($f['leave_type_id'] ?? null, fn (Builder $q, $id) => $q->where('leave_requests.leave_type_id', $id))
            ->when($f['employee_id'] ?? null, fn (Builder $q, $id) => $q->where('leave_requests.employee_id', $id))
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('leave_requests.end_date', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('leave_requests.start_date', '<=', $d))
            ->when($f['department_id'] ?? null, fn (Builder $q, $id) => $q->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                ->from('employee_assignments as la')->whereColumn('la.employee_id', 'leave_requests.employee_id')
                ->where('la.department_id', $id)
                ->whereColumn('la.effective_from', '<=', 'leave_requests.start_date')
                ->where(fn ($w) => $w->whereNull('la.effective_to')->orWhereColumn('la.effective_to', '>=', 'leave_requests.start_date'))))
            ->with(['employee:id,full_name,employee_number,photo_path', 'leaveType', 'reviewer:id,name'])
            ->orderBy($tab === 'pending' ? 'leave_requests.start_date' : 'leave_requests.updated_at', $tab === 'pending' ? 'asc' : 'desc')
            ->orderBy('leave_requests.id')
            ->paginate(25)->withQueryString();
    }

    /** @return array<string, mixed> everything the detail drawer shows */
    public function detail(User $viewer, LeaveRequest $request): array
    {
        $request->load(['employee', 'leaveType', 'balance', 'reviewer:id,name', 'dayRows']);
        $balance = $request->balance;
        $days = (string) $request->days;

        $impact = null;
        if ($balance && ! $balance->isUnlimited()) {
            $available = $balance->available();
            $impact = [
                'entitlement' => $balance->entitlement(),
                'used' => (string) $balance->used,
                'reserved' => (string) $balance->reserved,
                'available' => $available,
                // A pending request is already reserved, so approving it does not change "available".
                'after' => $request->status === LeaveStatus::Pending ? $available : null,
            ];
        }

        return [
            'request' => $request,
            'dates' => $request->dayRows->groupBy(fn (LeaveRequestDay $d) => $d->date->toDateString())
                ->map(fn ($slots) => $slots->pluck('slot')->sort()->values()->all())->all(),
            'impact' => $impact,
            'unlimited' => $balance?->isUnlimited() ?? false,
            'policy' => $request->policy_snapshot ?? [],
            'history' => Activity::query()->with('actor:id,name')
                ->where('entity_type', $request->getMorphClass())->where('entity_id', $request->id)
                ->orderBy('id')->get(),
            'requestedBy' => $request->requested_by ? User::withTrashed()->whereKey($request->requested_by)->value('name') : null,
            'canDecide' => $request->status === LeaveStatus::Pending && Gate::forUser($viewer)->allows('approve', $request),
            'canCancel' => $request->status->isLive()
                && ! ($request->status === LeaveStatus::Approved && $request->start_date->toDateString() <= $this->context->today()->toDateString())
                && Gate::forUser($viewer)->allows('cancel', $request),
            'days' => $days,
        ];
    }

    /**
     * Who is off when, for one month: employees in the viewer's scope with pending or approved leave.
     *
     * @return array{month: CarbonImmutable, dates: list<CarbonImmutable>, rows: Collection<int, array<string, mixed>>, holidays: array<string, string>}
     */
    public function month(User $user, string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth();
        $dates = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $dates[] = $d;
        }

        $days = LeaveRequestDay::query()
            ->join('leave_requests as r', 'r.id', '=', 'leave_request_days.leave_request_id')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->whereIn('r.status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->whereIn('leave_request_days.employee_id', $this->visible($user))
            ->whereBetween('leave_request_days.date', [$start->toDateString(), $end->toDateString()])
            ->get(['leave_request_days.employee_id', 'leave_request_days.date', 'leave_request_days.slot', 'r.status', 'r.id as request_id', 't.color', 't.name as type_name']);

        $employees = Employee::withTrashed()->whereIn('id', $days->pluck('employee_id')->unique())
            ->orderBy('full_name')->get(['id', 'full_name', 'employee_number', 'photo_path'])->keyBy('id');

        $rows = $days->groupBy('employee_id')->map(function ($slots, $employeeId) use ($employees) {
            $cells = [];
            foreach ($slots->groupBy(fn ($s) => $s->date->toDateString()) as $date => $daySlots) {
                $first = $daySlots->first();
                $cells[$date] = [
                    'half' => $daySlots->count() === 1 ? $first->slot : null,
                    'pending' => $first->status === LeaveStatus::Pending->value,
                    'color' => $first->color,
                    'type' => $first->type_name,
                    'request_id' => $first->request_id,
                ];
            }

            return ['employee' => $employees[$employeeId] ?? null, 'cells' => $cells];
        })->filter(fn ($r) => $r['employee'] !== null)->sortBy(fn ($r) => $r['employee']->full_name)->values();

        $holidays = Holiday::query()->where('company_id', $this->context->id())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->mapWithKeys(fn ($h) => [$h->date->toDateString() => $h->name])->all();

        return ['month' => $start, 'dates' => $dates, 'rows' => $rows, 'holidays' => $holidays];
    }

    /**
     * Per-type balances for a leave year plus the employee's requests (drafts excluded).
     *
     * @return array{year: int, years: list<int>, balances: list<array<string, mixed>>, requests: Collection<int, LeaveRequest>}
     */
    public function employeeSummary(Employee $employee, ?int $year = null): array
    {
        $current = $this->calendar->yearOf($this->context->today()->toDateString());
        $year ??= $current;

        $balances = [];
        foreach (LeaveType::query()->where('company_id', $this->context->id())->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get() as $type) {
            try {
                $balance = $this->balances->balanceFor($employee, $type, $year);
                $balances[] = ['type' => $type, 'balance' => $balance, 'error' => null];
            } catch (ApiException $e) {
                $balances[] = ['type' => $type, 'balance' => null, 'error' => $e->getMessage()];
            }
        }

        [$from, $to] = $this->calendar->yearBounds($year);

        return [
            'year' => $year,
            'years' => array_values(array_unique([$current - 1, $current, $current + 1, $year])),
            'balances' => $balances,
            'requests' => LeaveRequest::query()->where('employee_id', $employee->id)
                ->where('status', '!=', LeaveStatus::Draft->value)
                ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
                ->with(['leaveType', 'reviewer:id,name'])->orderByDesc('start_date')->get(),
        ];
    }

    /** Employees whose leave this user may see: all or team, from the view or approve permissions. */
    public function visible(User $user): Builder
    {
        $all = $user->hasPermission('leave.view') ? 'leave.view' : 'leave.approve';
        $team = $user->hasPermission('leave.view_team') ? 'leave.view_team' : 'leave.approve_team';

        return $this->scope->employees($user, $all, $team, false)->select('employees.id');
    }

    /** Employees whose leave this user may decide. */
    public function decidable(User $user): Builder
    {
        return $this->scope->employees($user, 'leave.approve', 'leave.approve_team', false)->select('employees.id');
    }

    private function notMine(User $user, Builder $people): Builder
    {
        return $people->when($user->employee, fn (Builder $q, $me) => $q->whereKeyNot($me->id));
    }
}

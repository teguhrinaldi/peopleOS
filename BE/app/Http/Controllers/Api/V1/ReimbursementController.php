<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ExpenseResources;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Reimbursements\ExpensePolicyService;
use App\Services\Reimbursements\ExpenseQuery;
use App\Services\Reimbursements\ReceiptStorage;
use App\Services\Reimbursements\ReimbursementPaymentService;
use App\Services\Reimbursements\ReimbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reimbursement claims. Ids are resolved inside the caller's visible set: a claim the caller may not see answers
 * 404 NOT_FOUND exactly like one that does not exist, so ids cannot be probed. Authorization of each action is then
 * re-checked by ExpenseRequestPolicy on the locked row inside the service.
 */
class ReimbursementController extends ApiController
{
    private const FIELD_RULES = [
        'expense_category_id' => ['required', 'integer', 'min:1'],
        'expense_date' => ['required', 'date_format:Y-m-d'],
        'submitted_amount' => ['required', 'numeric', 'gt:0'],
        'currency' => ['nullable', 'string', 'size:3'],
        'business_purpose' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:5000'],
        'receipt' => ['nullable', 'file'],
    ];

    public function __construct(
        private readonly ReimbursementService $service,
        private readonly ReimbursementPaymentService $payments,
        private readonly ExpenseQuery $query,
        private readonly ReceiptStorage $storage,
        private readonly ExpensePolicyService $policies,
    ) {}

    /** scope=mine (default) | review (what the caller may decide) | pay (payable) | all (everything the caller may see) */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,review,pay,all'],
            'status' => ['nullable', 'in:DRAFT,SUBMITTED,UNDER_REVIEW,APPROVED,REJECTED,SCHEDULED_FOR_PAYMENT,PAID,CANCELLED'],
            'category_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $this->authorize('viewAny', ExpenseRequest::class);

        $query = match ($request->query('scope', 'mine')) {
            'review' => $user->hasAnyPermission('expense.approve', 'expense.approve_team')
                ? $this->query->reviewable($user)
                : throw ApiException::make('FORBIDDEN', 'You cannot review reimbursements.', 403),
            'pay' => $user->hasPermission('expense.pay')
                ? $this->query->payable($user)
                : throw ApiException::make('FORBIDDEN', 'You cannot pay reimbursements.', 403),
            'all' => $user->hasAnyPermission('expense.view', 'expense.view_team', 'expense.approve', 'expense.approve_team', 'expense.pay', 'expense.manage')
                ? $this->query->visible($user)
                : throw ApiException::make('FORBIDDEN', 'You cannot view other people\'s reimbursements.', 403),
            default => $this->query->mine($user),
        };

        $query->with(ExpenseResources::WITH)
            ->when($request->filled('status'), fn ($q) => $q->where('expense_requests.status', $request->query('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('expense_requests.expense_category_id', $request->integer('category_id')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('expense_requests.employee_id', $request->integer('employee_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_requests.expense_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_requests.expense_date', '<=', $request->query('to')))
            ->when($request->filled('q'), fn ($q) => $q->where('expense_requests.request_number', 'like', '%'.addcslashes((string) $request->query('q'), '%_\\').'%'));

        return $this->paginated(
            $query->orderByDesc('expense_requests.id')->paginate($this->perPage($request)),
            fn (ExpenseRequest $r) => ExpenseResources::request($r, $user),
        );
    }

    /** Multipart or JSON. `submit=true` submits immediately; otherwise a DRAFT is saved (a receipt may be sent with it). */
    public function store(Request $request): JsonResponse
    {
        $request->validate(self::FIELD_RULES + ['submit' => ['nullable', 'boolean']]);
        $created = $this->service->create(
            $request->user(), $this->employee($request), $request->only(['expense_category_id', 'expense_date', 'submitted_amount', 'currency', 'business_purpose', 'description']),
            $request->file('receipt'), $request->boolean('submit'),
        );

        return $this->ok($this->present($created, $request), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->present($this->visible($request->user(), $id), $request));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate([
            'expense_category_id' => ['sometimes', 'integer', 'min:1'], 'expense_date' => ['sometimes', 'date_format:Y-m-d'],
            'submitted_amount' => ['sometimes', 'numeric', 'gt:0'], 'currency' => ['nullable', 'string', 'size:3'],
            'business_purpose' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'receipt' => ['nullable', 'file'],
        ]);
        $claim = $this->service->updateDraft($request->user(), $target, $request->only(['expense_category_id', 'expense_date', 'submitted_amount', 'currency', 'business_purpose', 'description']), $request->file('receipt'));

        return $this->ok($this->present($claim, $request));
    }

    /** Attach or replace the receipt of a draft (multipart `receipt`). */
    public function uploadReceipt(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['receipt' => ['required', 'file']]);
        $claim = $this->service->attachReceipt($request->user(), $target, $request->file('receipt'));

        return $this->ok($this->present($claim, $request));
    }

    /** Private file stream. 404 for claims the caller cannot see; the storage path is never revealed. */
    public function receipt(Request $request, int $id): StreamedResponse
    {
        $claim = $this->visible($request->user(), $id);
        $this->authorize('download', $claim);

        return $this->storage->download($claim);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $claim = $this->visible($request->user(), $id);

        return $this->ok([
            'events' => $claim->events()->with('actor')->get()->map(fn ($e) => ExpenseResources::event($e))->values(),
            'payment' => $claim->payment ? ExpenseResources::payment($claim->payment->load('payer')) : null,
        ]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->present($this->service->submit($request->user(), $this->visible($request->user(), $id)), $request));
    }

    public function review(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->present($this->service->startReview($request->user(), $this->visible($request->user(), $id)), $request));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['approved_amount' => ['nullable', 'numeric', 'gt:0'], 'adjustment_reason' => ['nullable', 'string', 'max:500'], 'note' => ['nullable', 'string', 'max:500']]);
        $claim = $this->service->approve($request->user(), $target, $request->input('approved_amount'), $request->input('adjustment_reason'), $request->input('note'));

        return $this->ok($this->present($claim, $request));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->ok($this->present($this->service->reject($request->user(), $target, (string) $request->input('reason')), $request));
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->ok($this->present($this->service->withdraw($request->user(), $target, $request->input('reason')), $request));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->ok($this->present($this->service->cancel($request->user(), $target, (string) $request->input('reason')), $request));
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate(['scheduled_for' => ['nullable', 'date_format:Y-m-d']]);

        return $this->ok($this->present($this->service->schedule($request->user(), $target, $request->input('scheduled_for')), $request));
    }

    /** Idempotent. Send an `idempotency_key` (body) or an `Idempotency-Key` header; retries with the same key return the same payment. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $target = $this->visible($request->user(), $id);
        $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:100'], 'method' => ['required', 'string', 'in:'.implode(',', array_diff(ExpensePayment::METHODS, ['PAYROLL']))],
            'reference' => ['nullable', 'string', 'max:120'], 'paid_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = (string) ($request->input('idempotency_key') ?: $request->header('Idempotency-Key', ''));

        [$claim, $payment, $replayed] = $this->payments->pay($request->user(), $target, $key, $request->only(['method', 'reference', 'paid_at', 'note']));

        return $this->ok($this->present($claim->load(ExpenseResources::WITH), $request), 200, ['idempotent_replay' => $replayed, 'payment_id' => $payment->id]);
    }

    private function visible(User $user, int $id): ExpenseRequest
    {
        $this->authorize('viewAny', ExpenseRequest::class);

        return $this->query->findVisible($user, $id)
            ?? throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    /** @return array<string, mixed> */
    private function present(ExpenseRequest $r, Request $request): array
    {
        return ExpenseResources::request($r->load(ExpenseResources::WITH), $request->user());
    }
}

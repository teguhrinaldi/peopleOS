<?php

namespace Tests\Support;

use App\Enums\ExpenseStatus;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Reimbursements\ExpenseCategoryService;
use App\Services\Reimbursements\ExpensePolicyService;
use App\Services\Reimbursements\ReimbursementPaymentService;
use App\Services\Reimbursements\ReimbursementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Reimbursement helpers on top of WorkplaceFixtures: a manager, an employee reporting to them, HR and an unrelated
 * employee, default categories and a faked private `local` disk. Currency is the company default (IDR).
 */
trait ReimbursementFixtures
{
    use WorkplaceFixtures;

    protected User $manager;

    protected Employee $managerEmployee;

    protected User $employeeUser;

    protected Employee $employee;

    protected User $hr;

    protected Employee $hrEmployee;

    protected User $stranger;

    protected Employee $strangerEmployee;

    protected User $admin;

    protected function setUpReimbursements(): void
    {
        $this->setUpWorkplace();
        Storage::fake('local');
        app(ExpenseCategoryService::class)->installDefaults();

        [$this->manager, $this->managerEmployee] = $this->makeStaff('manager');
        [$this->employeeUser, $this->employee] = $this->makeStaff('employee', [], ['manager_id' => $this->managerEmployee->id]);
        [$this->hr, $this->hrEmployee] = $this->makeStaff('hr');
        [$this->stranger, $this->strangerEmployee] = $this->makeStaff('employee');
        [$this->admin] = $this->makeStaff('admin');
    }

    protected function category(string $code = 'TRANSPORT'): ExpenseCategory
    {
        return ExpenseCategory::query()->where('code', $code)->firstOrFail();
    }

    protected function pdfBytes(string $marker = 'receipt'): string
    {
        return "%PDF-1.4\n% {$marker}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    protected function receipt(string $name = 'receipt.pdf', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->pdfBytes(uniqid()));
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'expense_category_id' => $this->category()->id,
            'expense_date' => now()->setTimezone('Asia/Jakarta')->subDays(2)->toDateString(),
            'submitted_amount' => '100000',
            'business_purpose' => 'Client visit',
            'description' => 'Taxi both ways',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function makeClaim(?Employee $employee = null, array $overrides = [], bool $submit = true, bool $withReceipt = true): ExpenseRequest
    {
        $employee ??= $this->employee;

        return app(ReimbursementService::class)->create($employee->user, $employee, $this->payload($overrides), $withReceipt ? $this->receipt() : null, $submit);
    }

    protected function underReview(?Employee $employee = null, array $overrides = []): ExpenseRequest
    {
        $claim = $this->makeClaim($employee, $overrides);

        return app(ReimbursementService::class)->startReview($this->hr, $claim);
    }

    protected function approved(?Employee $employee = null, array $overrides = []): ExpenseRequest
    {
        return app(ReimbursementService::class)->approve($this->hr, $this->underReview($employee, $overrides));
    }

    protected function scheduled(?Employee $employee = null, array $overrides = []): ExpenseRequest
    {
        return app(ReimbursementService::class)->schedule($this->hr, $this->approved($employee, $overrides));
    }

    protected function paid(?Employee $employee = null): ExpenseRequest
    {
        $claim = $this->scheduled($employee);
        [$paid] = app(ReimbursementPaymentService::class)->pay($this->hr, $claim, 'key-'.uniqid(), ['method' => 'BANK_TRANSFER', 'reference' => 'TRX-1']);

        return $paid;
    }

    protected function setPolicy(array $values): void
    {
        app(ExpensePolicyService::class)->update($this->admin, $values);
    }

    protected function claimStatus(ExpenseRequest $claim): string
    {
        return $claim->fresh()->status->value;
    }

    /** All statuses, for invalid-transition matrices. @return list<ExpenseStatus> */
    protected function allStatuses(): array
    {
        return ExpenseStatus::cases();
    }
}

<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Reimbursements\ExpenseCategoryService;
use App\Services\Reimbursements\ExpensePolicyService;
use App\Services\Reimbursements\ReimbursementPaymentService;
use App\Services\Reimbursements\ReimbursementService;
use App\Support\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Default expense categories + company policy (always), and a handful of demo claims for the demo company covering every
 * stage: draft, submitted, under review, approved + adjusted, scheduled, paid and rejected. Claims go through the real
 * services (state machine, audit, notifications). Idempotent: demo claims are created only when the company has none.
 */
class ReimbursementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(CompanyContext::class);
        $context->forget();

        $company = Company::query()->where('name', 'PeopleOS Demo Company')->first() ?? Company::query()->orderBy('id')->first();
        if (! $company) {
            $this->command?->warn('ReimbursementDemoSeeder: run DemoSeeder first (no company).');

            return;
        }

        app(ExpenseCategoryService::class)->installDefaults();
        app(ExpensePolicyService::class)->current();

        if (ExpenseRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        $hr = User::query()->where('email', 'hr@peopleos.test')->first();
        $michael = Employee::query()->where('employee_number', 'EMP-0012')->first();
        $olivia = Employee::query()->where('employee_number', 'EMP-0013')->first();
        $michaelUser = $michael?->user;
        if (! $hr || ! $michael || ! $michaelUser) {
            return;
        }

        $service = app(ReimbursementService::class);
        $payments = app(ReimbursementPaymentService::class);
        $category = fn (string $code) => ExpenseCategory::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail()->id;
        $today = $context->today();
        $claim = fn (string $code, int $daysAgo, string $amount, string $purpose, bool $submit = true, ?Employee $who = null) => $service->create(
            ($who ?? $michael)->user, $who ?? $michael,
            ['expense_category_id' => $category($code), 'expense_date' => $today->subDays($daysAgo)->toDateString(), 'submitted_amount' => $amount, 'business_purpose' => $purpose, 'description' => $purpose],
            $this->receipt($purpose), $submit,
        );

        $claim('TRANSPORT', 1, '185000', 'Taxi to client workshop', submit: false);
        $claim('MEALS', 2, '240000', 'Team lunch with client');

        $review = $claim('TRANSPORT', 4, '420000', 'Airport transfer for vendor visit');
        $service->startReview($hr, $review);

        $adjusted = $claim('OFFICE_SUPPLIES', 6, '650000', 'Printer toner and notebooks');
        $service->startReview($hr, $adjusted);
        $service->approve($hr, $adjusted, '600000', 'Notebooks above the stationery allowance', 'Approved with adjustment');

        $scheduled = $claim('COMMUNICATION', 9, '350000', 'Mobile data plan for field work');
        $service->startReview($hr, $scheduled);
        $service->approve($hr, $scheduled);
        $service->schedule($hr, $scheduled, $today->addDays(3)->toDateString());

        $paid = $claim('ACCOMMODATION', 14, '1800000', 'Hotel for Surabaya training');
        $service->startReview($hr, $paid);
        $service->approve($hr, $paid);
        $service->schedule($hr, $paid);
        $payments->pay($hr, $paid, 'demo-pay-'.$paid->id, ['method' => 'BANK_TRANSFER', 'reference' => 'TRX-'.$today->format('Ymd').'-0001', 'note' => 'Paid with the weekly transfer batch']);

        $rejected = $claim('MEALS', 12, '90000', 'Personal dinner');
        $service->startReview($hr, $rejected);
        $service->reject($hr, $rejected, 'Not a business expense.');

        if ($olivia?->user) {
            $claim('PARKING_TOLL', 3, '45000', 'Toll and parking for site visit', who: $olivia);
        }

        $this->command?->info('Demo reimbursements created: '.ExpenseRequest::query()->where('company_id', $company->id)->count());
    }

    /** A small real PDF, written through the same upload path as a user's receipt. */
    private function receipt(string $title): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($path, "%PDF-1.4\n% Receipt: {$title}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, 'receipt-'.substr(md5($title), 0, 8).'.pdf', 'application/pdf', null, true);
    }
}

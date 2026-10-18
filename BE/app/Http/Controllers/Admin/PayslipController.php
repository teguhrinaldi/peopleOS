<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Services\Admin\PayslipDesk;
use App\Services\Payroll\PayslipDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Payslip list, detail and authorized PDF streaming. The file path never leaves the server. */
class PayslipController extends Controller
{
    public function __construct(private readonly PayslipDesk $desk) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'period_id' => ['nullable', 'integer'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        return view('admin.payslips.index', [
            'payslips' => $this->desk->paginate($filters),
            'periods' => $this->desk->periodOptions(),
            'years' => $this->desk->years(),
            'filters' => $filters,
            'canRegenerate' => $request->user()->hasPermission('payroll.finalize'),
        ]);
    }

    public function show(Request $request, int $payslip): View
    {
        $slip = $this->visible($request, $payslip);

        return view('admin.payslips.show', ['payslip' => $slip, 'item' => $slip->item, 'run' => $slip->item->run, 'period' => $slip->period, 'canRegenerate' => $request->user()->hasPermission('payroll.finalize')]);
    }

    public function pdf(Request $request, int $payslip, PayslipDocuments $documents): StreamedResponse
    {
        return $documents->stream($this->visible($request, $payslip), $request->boolean('download'));
    }

    public function regenerate(Request $request, int $payslip): RedirectResponse
    {
        $slip = $this->visible($request, $payslip);
        $written = $this->desk->regenerateMissing($request->user(), $slip);

        return back()->with('success', $written ? 'The missing PDF was generated again from the stored payslip data.' : 'The PDF file is already in place; nothing needed regenerating.');
    }

    private function visible(Request $request, int $id): Payslip
    {
        return $this->desk->findVisible($request->user(), $id) ?? abort(404);
    }
}

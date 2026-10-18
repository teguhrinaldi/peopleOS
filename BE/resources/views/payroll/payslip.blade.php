{{-- Payslip PDF (dompdf). Rendered only from the frozen payroll snapshot. DejaVu Sans ships with dompdf. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $payslip->payslip_number }}</title>
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #0f1b3d; }
    .band { background: #0d2049; color: #ffffff; padding: 26px 40px 22px; }
    .band table { width: 100%; border-collapse: collapse; }
    .brand { font-size: 15pt; font-weight: bold; letter-spacing: 0.2px; }
    .brand-sub { color: #aab8d6; font-size: 8.5pt; margin-top: 3px; }
    .doc-title { text-align: right; font-size: 13pt; font-weight: bold; }
    .doc-no { text-align: right; color: #aab8d6; font-size: 8.5pt; margin-top: 3px; }
    .accent { height: 4px; background: #2563eb; }
    .content { padding: 22px 40px 0; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    .meta td { vertical-align: top; width: 50%; padding: 0; }
    .card { border: 1px solid #dfe5f1; border-radius: 6px; padding: 10px 12px; }
    .card + .card { margin-top: 8px; }
    .label { color: #5f6c87; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.6px; }
    .value { font-size: 10pt; font-weight: bold; margin-top: 2px; }
    .kv { width: 100%; border-collapse: collapse; }
    .kv td { padding: 2px 0; font-size: 8.5pt; }
    .kv td.k { color: #5f6c87; width: 45%; }
    h3 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.8px; color: #2563eb; margin: 16px 0 6px; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th { text-align: left; font-size: 7.5pt; color: #5f6c87; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #dfe5f1; padding: 5px 6px; }
    table.lines td { padding: 6px; border-bottom: 1px solid #eef1f7; }
    table.lines td.amt, table.lines th.amt { text-align: right; white-space: nowrap; }
    table.lines td.desc { color: #5f6c87; font-size: 8pt; }
    table.lines tr.total td { font-weight: bold; border-bottom: none; border-top: 1px solid #c9d3e6; }
    .empty { color: #5f6c87; font-style: italic; }
    .net { margin-top: 18px; background: #eef4ff; border: 1px solid #c7d7fe; border-radius: 6px; padding: 12px 14px; }
    .net table { width: 100%; border-collapse: collapse; }
    .net .net-label { font-size: 10pt; font-weight: bold; color: #0d2049; }
    .net .net-value { text-align: right; font-size: 15pt; font-weight: bold; color: #0d2049; }
    .negative { color: #b91c1c !important; }
    .summary { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .summary td { font-size: 8.5pt; padding: 2px 0; }
    .summary td.amt { text-align: right; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 40px; font-size: 7.5pt; color: #5f6c87; border-top: 1px solid #dfe5f1; }
    .footer table { width: 100%; border-collapse: collapse; }
</style>
</head>
<body>
<div class="band">
    <table>
        <tr>
            <td>
                <div class="brand">{{ $company['name'] ?? '' }}</div>
                @if (! empty($company['legal_name']) && $company['legal_name'] !== ($company['name'] ?? null))
                    <div class="brand-sub">{{ $company['legal_name'] }}</div>
                @endif
                @if (! empty($company['address']))
                    <div class="brand-sub">{{ $company['address'] }}</div>
                @endif
            </td>
            <td>
                <div class="doc-title">PAYSLIP{{ $period->isAdjustment() ? ' · ADJUSTMENT' : '' }}</div>
                <div class="doc-no">No. {{ $payslip->payslip_number }}</div>
            </td>
        </tr>
    </table>
</div>
<div class="accent"></div>

<div class="content">
    <table class="meta">
        <tr>
            <td style="padding-right: 8px;">
                <div class="card">
                    <div class="label">Employee</div>
                    <div class="value">{{ $item->employee_name }}</div>
                    <table class="kv" style="margin-top: 6px;">
                        <tr><td class="k">Employee number</td><td>{{ $item->employee_number }}</td></tr>
                        <tr><td class="k">Department</td><td>{{ $item->department_name ?? '-' }}</td></tr>
                        <tr><td class="k">Position</td><td>{{ $item->position_name ?? '-' }}</td></tr>
                    </table>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="card">
                    <div class="label">Payroll period</div>
                    <div class="value">{{ $period->name }}</div>
                    <table class="kv" style="margin-top: 6px;">
                        <tr><td class="k">Dates</td><td>{{ $period->start_date->format('d M Y') }} – {{ $period->end_date->format('d M Y') }}</td></tr>
                        <tr><td class="k">Pay date</td><td>{{ $period->pay_date?->format('d M Y') ?? '-' }}</td></tr>
                        @unless ($period->isAdjustment())
                            <tr><td class="k">Present / working days</td><td>{{ $item->present_days }} / {{ $item->working_days }}</td></tr>
                        @endunless
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <h3>Earnings</h3>
    <table class="lines">
        <thead><tr><th>Component</th><th>Details</th><th class="amt">Amount ({{ $currency }})</th></tr></thead>
        <tbody>
        @forelse ($earnings as $line)
            <tr>
                <td>{{ $line->component_name }}</td>
                <td class="desc">
                    @if ($line->source_key === 'overtime')
                        {{-- Overtime rule engine: hours x hourly rate x multiplier = amount, from the frozen line. --}}
                        {{ number_format((float) $line->quantity, 2) }} h × {{ ($money2 ?? $money)($line->unit_amount) }}/h × {{ rtrim(rtrim((string) $line->multiplier, '0'), '.') }} = {{ $money($line->amount) }}
                        <br><span style="font-size: 7pt;">
                            @foreach ($line->source_refs ?? [] as $ref)
                                {{ \Carbon\Carbon::parse($ref['work_date'])->format('d M') }}: {{ $ref['minutes'] }} min{{ ! empty($ref['carried_over']) ? ' (late approval)' : '' }}{{ $loop->last ? '' : ';' }}
                            @endforeach
                        </span>
                    @else
                        {{ $line->adjustment_reason ?? $line->description }}
                    @endif
                </td>
                <td class="amt">{{ $money($line->amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">No earnings</td></tr>
        @endforelse
        <tr class="total"><td colspan="2">Gross pay</td><td class="amt">{{ $money($item->gross_pay) }}</td></tr>
        </tbody>
    </table>

    <h3>Deductions</h3>
    <table class="lines">
        <thead><tr><th>Component</th><th>Details</th><th class="amt">Amount ({{ $currency }})</th></tr></thead>
        <tbody>
        @forelse ($deductions as $line)
            <tr>
                <td>{{ $line->component_name }}</td>
                <td class="desc">{{ $line->adjustment_reason ?? $line->description }}</td>
                <td class="amt">{{ $money($line->amount) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">No deductions</td></tr>
        @endforelse
        <tr class="total"><td colspan="2">Total deductions</td><td class="amt">{{ $money($item->total_deductions) }}</td></tr>
        </tbody>
    </table>

    <div class="net">
        <table>
            <tr>
                <td class="net-label">Net pay</td>
                <td class="net-value {{ $item->has_negative_net ? 'negative' : '' }}">{{ $currency }} {{ $money($item->net_pay) }}</td>
            </tr>
        </table>
        <table class="summary">
            <tr><td>Gross pay</td><td class="amt">{{ $money($item->gross_pay) }}</td></tr>
            <tr><td>Total deductions</td><td class="amt">− {{ $money($item->total_deductions) }}</td></tr>
        </table>
    </div>
</div>

<div class="footer">
    <table>
        <tr>
            <td>Issued {{ $issuedAt->format('d M Y H:i') }} · Generated by PeopleOS</td>
            <td style="text-align: right;">Confidential – for the named employee only</td>
        </tr>
    </table>
</div>
</body>
</html>

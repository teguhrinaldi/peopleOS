import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/money.dart';
import 'package:peopleos/core/utils/paged.dart';
import 'package:peopleos/core/utils/zoned_time.dart';

@immutable
class PayslipPeriod {
  const PayslipPeriod({
    required this.code,
    required this.name,
    this.startDate,
    this.endDate,
    this.payDate,
  });

  final String code;
  final String name;
  final DateTime? startDate;
  final DateTime? endDate;
  final DateTime? payDate;

  int? get year => (startDate ?? payDate)?.year;

  factory PayslipPeriod.fromJson(JsonReader? j) => PayslipPeriod(
    code: j?.str('code') ?? '',
    name: j?.str('name') ?? 'Payslip',
    startDate: j?.date('start_date'),
    endDate: j?.date('end_date'),
    payDate: j?.date('pay_date'),
  );
}

/// One earning or deduction line of a payslip.
@immutable
class PayslipLine {
  const PayslipLine({
    required this.code,
    required this.name,
    required this.amount,
    this.calculation,
    this.description,
    this.adjustmentReason,
  });

  final String code;
  final String name;
  final Money amount;

  /// e.g. FIXED, PER_PRESENT_DAY.
  final String? calculation;

  /// Calculation hint from the server, e.g. "21 days x 50,000".
  final String? description;
  final String? adjustmentReason;

  factory PayslipLine.fromJson(JsonReader j) => PayslipLine(
    code: j.str('code') ?? '',
    name: j.str('name') ?? j.str('code') ?? '',
    amount: Money.parse(j.map['amount']),
    calculation: j.str('calculation'),
    description: j.str('description'),
    adjustmentReason: j.str('adjustment_reason'),
  );
}

@immutable
class PayslipInputs {
  const PayslipInputs({
    this.workingDays,
    this.presentDays,
    this.overtimeMinutes = 0,
    this.unpaidLeaveDays = '0.0',
  });

  final int? workingDays;
  final int? presentDays;
  final int overtimeMinutes;
  final String unpaidLeaveDays;

  factory PayslipInputs.fromJson(JsonReader j) => PayslipInputs(
    workingDays: j.intOrNull('working_days'),
    presentDays: j.intOrNull('present_days'),
    overtimeMinutes: j.intOr('overtime_minutes', 0),
    unpaidLeaveDays: j.str('unpaid_leave_days') ?? '0.0',
  );
}

@immutable
class PayslipEmployee {
  const PayslipEmployee({
    required this.name,
    this.number,
    this.department,
    this.position,
    this.branch,
  });

  final String name;
  final String? number;
  final String? department;
  final String? position;
  final String? branch;
}

@immutable
class Payslip {
  const Payslip({
    required this.id,
    required this.number,
    required this.period,
    required this.status,
    required this.currency,
    required this.decimals,
    required this.gross,
    required this.deductionsTotal,
    required this.net,
    this.issuedAt,
    this.hasPdf = false,
    this.companyName,
    this.companyAddress,
    this.employee,
    this.inputs,
    this.basicSalary,
    this.earnings = const [],
    this.deductions = const [],
  });

  final int id;
  final String number;
  final PayslipPeriod period;

  /// FINALIZED | PAID
  final String status;
  final String currency;
  final int decimals;
  final Money gross;
  final Money deductionsTotal;
  final Money net;
  final ZonedTime? issuedAt;
  final bool hasPdf;

  // Detail-only fields.
  final String? companyName;
  final String? companyAddress;
  final PayslipEmployee? employee;
  final PayslipInputs? inputs;
  final Money? basicSalary;
  final List<PayslipLine> earnings;
  final List<PayslipLine> deductions;

  bool get hasDetail => employee != null;
  bool get isPaid => status == 'PAID';

  /// Formats with the payslip's own currency and decimals.
  String money(Money m, [String locale = 'en']) =>
      m.format(currency: currency, decimals: decimals, locale: locale);

  factory Payslip.fromJson(JsonReader j) {
    final item = j.obj('item');
    final company = j.obj('company');
    final emp = item?.obj('employee');
    final inputs = item?.obj('inputs');
    return Payslip(
      id: j.intOrNull('id') ?? 0,
      number: j.str('payslip_number') ?? '',
      period: PayslipPeriod.fromJson(j.obj('period')),
      status: j.str('status') ?? 'FINALIZED',
      currency: j.str('currency') ?? 'IDR',
      decimals: j.intOr('decimals', 0),
      gross: Money.parse(j.map['gross_pay']),
      deductionsTotal: Money.parse(j.map['total_deductions']),
      net: Money.parse(j.map['net_pay']),
      issuedAt: j.time('issued_at'),
      hasPdf: j.boolOr('has_pdf', false),
      companyName: company?.str('legal_name') ?? company?.str('name'),
      companyAddress: company?.str('address'),
      employee: emp == null
          ? null
          : PayslipEmployee(
              name: emp.str('full_name') ?? '',
              number: emp.str('employee_number'),
              department: emp.str('department'),
              position: emp.str('position'),
              branch: emp.str('branch'),
            ),
      inputs: inputs == null ? null : PayslipInputs.fromJson(inputs),
      basicSalary: item?.map['basic_salary'] == null
          ? null
          : Money.parse(item!.map['basic_salary']),
      earnings:
          item?.objects('earnings').map(PayslipLine.fromJson).toList() ??
          const [],
      deductions:
          item?.objects('deductions').map(PayslipLine.fromJson).toList() ??
          const [],
    );
  }
}

abstract class PayslipRepository {
  Future<Paged<Payslip>> list({int? year, int page = 1});
  Future<Payslip> detail(int id);

  /// The PDF, fetched with the bearer header (never a bare URL).
  Future<DownloadedFile> pdf(Payslip payslip);
}

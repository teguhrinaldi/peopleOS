import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/demo/demo_leave.dart';

/// Demo payslips: the last three completed months, with the same shape and
/// numbers as the Laravel demo (Michael: basic 9,000,000 from July 2026,
/// 8,000,000 before; transport 50,000 and meal 35,000 per present day;
/// overtime 50,000/hour; cooperative savings 100,000).
List<Map<String, dynamic>> demoPayslips(DateTime today, ZonedTime now) {
  final out = <Map<String, dynamic>>[];
  for (var back = 1; back <= 3; back++) {
    final first = DateTime(today.year, today.month - back);
    out.add(_payslip(first, now, back));
  }
  return out;
}

const _months = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

String _money(int v) => '$v.00';

Map<String, dynamic> _payslip(DateTime first, ZonedTime now, int index) {
  final last = DateTime(first.year, first.month + 1, 0);
  final workingDays = demoWorkingDates(first, last).length;
  final present = workingDays - (first.month % 3 == 0 ? 2 : 1);
  final overtimeHours = first.month % 2 == 0 ? 2 : 0;
  final basic = first.isBefore(DateTime(2026, 7)) ? 8000000 : 9000000;
  final transport = 50000 * present;
  final meal = 35000 * present;
  final overtime = 50000 * overtimeHours;
  const coop = 100000;
  final gross = basic + transport + meal + overtime;
  final net = gross - coop;
  final code = '${first.year}-${first.month.toString().padLeft(2, '0')}';
  final id = 100 + first.year * 12 + first.month;
  final issued = ZonedTime.fromWall(last, 15, 0, now.offset);

  Map<String, dynamic> line(
    String code,
    String name,
    String kind,
    String calc,
    int amount, {
    String? quantity,
    int? unit,
    String? description,
  }) => {
    'code': code,
    'name': name,
    'kind': kind,
    'calculation': calc,
    'quantity': quantity,
    'unit_amount': unit == null ? null : _money(unit),
    'amount': _money(amount),
    'description': description,
    'adjustment_reason': null,
  };

  return {
    'id': id,
    'payslip_number': 'PS-${code.replaceAll('-', '')}-0006',
    'period': {
      'id': index,
      'code': code,
      'name': '${_months[first.month - 1]} ${first.year}',
      'type': 'REGULAR',
      'start_date': _d(first),
      'end_date': _d(last),
      'pay_date': _d(last),
    },
    'status': 'PAID',
    'currency': 'IDR',
    'decimals': 0,
    'gross_pay': _money(gross),
    'total_deductions': _money(coop),
    'net_pay': _money(net),
    'issued_at': issued.toIso(),
    'has_pdf': true,
    'pdf_url': '/api/v1/payslips/$id/pdf',
    'company': {
      'name': 'PeopleOS Demo Company',
      'legal_name': 'PT PeopleOS Demo Indonesia',
      'address': 'Jl. Asia Afrika No. 65, Bandung, Jawa Barat',
    },
    'item': {
      'employee': {
        'employee_number': 'EMP-0012',
        'full_name': 'Michael Carter',
        'department': 'Marketing',
        'position': 'Marketing Staff',
        'branch': 'Bandung Head Office',
        'employment_type': 'permanent',
        'join_date': '2022-01-15',
      },
      'inputs': {
        'salary_reference_date': _d(last),
        'working_days': workingDays,
        'present_days': present,
        'overtime_minutes': overtimeHours * 60,
        'unpaid_leave_days': '0.0',
      },
      'basic_salary': _money(basic),
      'earnings': [
        line('BASIC', 'Basic Salary', 'EARNING', 'FIXED', basic),
        line(
          'TRANSPORT',
          'Transport Allowance',
          'EARNING',
          'PER_PRESENT_DAY',
          transport,
          quantity: '$present.00',
          unit: 50000,
          description: '$present days x 50,000',
        ),
        line(
          'MEAL',
          'Meal Allowance',
          'EARNING',
          'PER_PRESENT_DAY',
          meal,
          quantity: '$present.00',
          unit: 35000,
          description: '$present days x 35,000',
        ),
        if (overtime > 0)
          line(
            'OVERTIME',
            'Overtime',
            'EARNING',
            'PER_OVERTIME_HOUR',
            overtime,
            quantity: '$overtimeHours.00',
            unit: 50000,
            description: '$overtimeHours.00 h x 50,000',
          ),
      ],
      'deductions': [
        line('COOP', 'Cooperative Savings', 'DEDUCTION', 'FIXED', coop),
      ],
    },
  };
}

String _d(DateTime d) => formatDateOnly(d);

/// Documents of the demo employee, with expiry statuses computed from the
/// demo clock exactly like the server (EXPIRING = within 30 days). The
/// HR-only "Disciplinary notes" document exists server-side but is never
/// returned here, so detail(…) answers 404 like the real API.
List<Map<String, dynamic>> demoDocuments(DateTime today, ZonedTime now) {
  Map<String, dynamic> doc(
    int id,
    String title,
    int typeId,
    String typeCode,
    String typeName, {
    required bool company,
    DateTime? expiry,
    required int uploadedDaysAgo,
  }) {
    int? days;
    var status = 'ACTIVE';
    if (expiry != null) {
      days = expiry.difference(today).inDays;
      status = days < 0 ? 'EXPIRED' : (days <= 30 ? 'EXPIRING' : 'ACTIVE');
    }
    final file = '${title.replaceAll(RegExp(r'[^A-Za-z0-9]+'), '_')}.pdf';
    return {
      'id': id,
      'title': title,
      'document_type': {'id': typeId, 'code': typeCode, 'name': typeName},
      'employee': company
          ? null
          : {
              'id': 12,
              'full_name': 'Michael Carter',
              'employee_number': 'EMP-0012',
            },
      'is_company_document': company,
      'status': status,
      'expiry_date': expiry == null ? null : _d(expiry),
      'days_until_expiry': days,
      'file': {
        'name': file,
        'extension': 'pdf',
        'mime_type': 'application/pdf',
        'size_bytes': 2300 + id * 7,
      },
      'download_url': '/api/v1/documents/$id/download',
      'uploaded_at': ZonedTime.fromWall(
        today.subtract(Duration(days: uploadedDaysAgo)),
        9,
        30,
        now.offset,
      ).toIso(),
    };
  }

  DateTime plus(int d) => DateTime(today.year, today.month, today.day + d);
  return [
    doc(
      35,
      'Employee Handbook',
      5,
      'COMPANY_DOCUMENT',
      'Company Document',
      company: true,
      uploadedDaysAgo: 18,
    ),
    doc(
      36,
      'Code of Conduct',
      5,
      'COMPANY_DOCUMENT',
      'Company Document',
      company: true,
      uploadedDaysAgo: 142,
    ),
    doc(
      37,
      'Holiday Calendar ${today.year}',
      5,
      'COMPANY_DOCUMENT',
      'Company Document',
      company: true,
      expiry: DateTime(today.year, 12, 31),
      uploadedDaysAgo: 165,
    ),
    doc(
      31,
      'Digital Marketing Fundamentals Certificate',
      3,
      'CERTIFICATE',
      'Certificate',
      company: false,
      uploadedDaysAgo: 148,
    ),
    doc(
      18,
      'National ID Card (reference copy)',
      2,
      'IDENTIFICATION',
      'Identification Reference',
      company: false,
      expiry: plus(12),
      uploadedDaysAgo: 204,
    ),
    doc(
      22,
      'First Aid Certificate',
      3,
      'CERTIFICATE',
      'Certificate',
      company: false,
      expiry: plus(-50),
      uploadedDaysAgo: 400,
    ),
    doc(
      6,
      'Employment Contract',
      1,
      'EMPLOYMENT_CONTRACT',
      'Employment Contract',
      company: false,
      uploadedDaysAgo: 1366,
    ),
  ];
}

const demoDocumentTypes = [
  {'id': 1, 'code': 'EMPLOYMENT_CONTRACT', 'name': 'Employment Contract'},
  {'id': 2, 'code': 'IDENTIFICATION', 'name': 'Identification Reference'},
  {'id': 3, 'code': 'CERTIFICATE', 'name': 'Certificate'},
  {'id': 4, 'code': 'PAYSLIP', 'name': 'Payslip'},
  {'id': 5, 'code': 'COMPANY_DOCUMENT', 'name': 'Company Document'},
  {'id': 6, 'code': 'OTHER', 'name': 'Other'},
];

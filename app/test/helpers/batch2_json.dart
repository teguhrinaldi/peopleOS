Map<String, dynamic> payslipSummaryJson({
  int id = 22,
  String number = 'PS-202608-0006',
  String code = '2026-08',
  String name = 'August 2026',
  String status = 'PAID',
  String net = '10615000.00',
  int decimals = 0,
  String currency = 'IDR',
}) => {
  'id': id,
  'payslip_number': number,
  'period': {
    'id': 2,
    'code': code,
    'name': name,
    'type': 'REGULAR',
    'start_date': '$code-01',
    'end_date': '$code-31',
    'pay_date': '$code-31',
  },
  'status': status,
  'currency': currency,
  'decimals': decimals,
  'gross_pay': '10715000.00',
  'total_deductions': '100000.00',
  'net_pay': net,
  'issued_at': '2026-08-31T15:00:00+07:00',
  'has_pdf': true,
  'pdf_url': 'http://127.0.0.1:8000/api/v1/payslips/$id/pdf',
};

Map<String, dynamic> payslipDetailJson({int id = 22}) => {
  ...payslipSummaryJson(id: id),
  'company': {
    'name': 'PeopleOS Demo Company',
    'address': 'Jl. Asia Afrika No. 65, Bandung',
    'legal_name': 'PT PeopleOS Demo Indonesia',
  },
  'item': {
    'employee': {
      'employee_number': 'EMP-0012',
      'full_name': 'Michael Carter',
      'department': 'Marketing',
      'position': 'Marketing Staff',
      'branch': 'Bandung Head Office',
    },
    'inputs': {
      'working_days': 20,
      'present_days': 19,
      'overtime_minutes': 120,
      'unpaid_leave_days': '0.0',
    },
    'basic_salary': '9000000.00',
    'earnings': [
      {
        'code': 'BASIC',
        'name': 'Basic Salary',
        'calculation': 'FIXED',
        'amount': '9000000.00',
        'description': null,
      },
      {
        'code': 'TRANSPORT',
        'name': 'Transport Allowance',
        'calculation': 'PER_PRESENT_DAY',
        'amount': '950000.00',
        'description': '19 days x 50,000',
      },
      {
        'code': 'OVERTIME',
        'name': 'Overtime',
        'amount': '100000.00',
        'description': '2.00 h x 50,000',
      },
    ],
    'deductions': [
      {'code': 'COOP', 'name': 'Cooperative Savings', 'amount': '100000.00'},
    ],
  },
};

Map<String, dynamic> documentJson({
  int id = 18,
  String title = 'National ID Card (reference copy)',
  String status = 'EXPIRING',
  int? days = 12,
  String? expiry = '2026-10-22',
  bool company = false,
}) => {
  'id': id,
  'title': title,
  'document_type': {
    'id': 2,
    'code': 'IDENTIFICATION',
    'name': 'Identification Reference',
  },
  'employee': company
      ? null
      : {'id': 6, 'full_name': 'Michael Carter', 'employee_number': 'EMP-0012'},
  'is_company_document': company,
  'status': status,
  'expiry_date': expiry,
  'days_until_expiry': days,
  'file': {
    'name': 'National_ID.pdf',
    'extension': 'pdf',
    'mime_type': 'application/pdf',
    'size_bytes': 2367,
  },
  'download_url': '/api/v1/documents/$id/download',
  'uploaded_at': '2026-03-20T09:13:00+07:00',
};

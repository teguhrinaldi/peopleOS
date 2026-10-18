import 'package:flutter/foundation.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';

@immutable
class NamedRef {
  const NamedRef(this.id, this.name);
  final int id;
  final String name;

  static NamedRef? from(JsonReader? j, {String nameKey = 'name'}) {
    if (j == null) return null;
    final id = j.intOrNull('id');
    final name = j.str(nameKey);
    if (id == null || name == null) return null;
    return NamedRef(id, name);
  }
}

@immutable
class ShiftRef {
  const ShiftRef({
    required this.id,
    required this.name,
    required this.startTime,
    required this.endTime,
  });

  final int id;
  final String name;
  final String startTime;
  final String endTime;

  String get label => '$startTime - $endTime';
}

/// Employee profile (`Employee object` in docs/api.md).
@immutable
class Employee {
  const Employee({
    required this.id,
    required this.employeeNumber,
    required this.fullName,
    required this.status,
    this.photoUrl,
    this.gender,
    this.dateOfBirth,
    this.phone,
    this.email,
    this.address,
    this.joinDate,
    this.employmentType,
    this.department,
    this.position,
    this.branch,
    this.manager,
    this.workLocation,
    this.shift,
  });

  final int id;
  final String employeeNumber;
  final String fullName;
  final String status;
  final String? photoUrl;
  final String? gender;
  final DateTime? dateOfBirth;
  final String? phone;
  final String? email;
  final String? address;
  final DateTime? joinDate;
  final String? employmentType;
  final NamedRef? department;
  final NamedRef? position;
  final NamedRef? branch;
  final NamedRef? manager;
  final NamedRef? workLocation;
  final ShiftRef? shift;

  bool get isActive => status == 'ACTIVE';

  factory Employee.fromJson(JsonReader j) {
    final shift = j.obj('shift');
    return Employee(
      id: j.intOrNull('id') ?? 0,
      employeeNumber: j.str('employee_number') ?? '',
      fullName: j.requireStr('full_name'),
      status: j.str('status') ?? 'ACTIVE',
      photoUrl: j.str('photo_url'),
      gender: j.str('gender'),
      dateOfBirth: j.date('date_of_birth'),
      phone: j.str('phone'),
      email: j.str('email'),
      address: j.str('address'),
      joinDate: j.date('join_date'),
      employmentType: j.str('employment_type'),
      department: NamedRef.from(j.obj('department')),
      position: NamedRef.from(j.obj('position')),
      branch: NamedRef.from(j.obj('branch')),
      manager: NamedRef.from(j.obj('manager'), nameKey: 'full_name'),
      workLocation: NamedRef.from(j.obj('work_location')),
      shift: shift == null
          ? null
          : ShiftRef(
              id: shift.intOrNull('id') ?? 0,
              name: shift.str('name') ?? '',
              startTime: shift.str('start_time') ?? '',
              endTime: shift.str('end_time') ?? '',
            ),
    );
  }
}

@immutable
class Company {
  const Company({required this.name, required this.timezone});
  final String name;
  final String timezone;
}

/// Everything known after a successful login or `/auth/me`.
@immutable
class Session {
  const Session({
    required this.userName,
    required this.userEmail,
    required this.employee,
    required this.company,
    this.permissions = const [],
    this.serverLocale,
  });

  final String userName;
  final String userEmail;
  final Employee employee;
  final Company company;
  final List<String> permissions;

  /// The user's saved language preference on the server (null = none).
  final String? serverLocale;

  factory Session.fromJson(Map<String, dynamic> raw) {
    final j = JsonReader(raw);
    final user = j.obj('user');
    final employee = j.obj('employee');
    final company = j.obj('company');
    return Session(
      userName: user?.str('name') ?? '',
      userEmail: user?.str('email') ?? '',
      employee: Employee.fromJson(
        employee ??
            (throw const ForbiddenException(
              'No employee profile is linked to this account.',
              code: 'NO_EMPLOYEE_PROFILE',
            )),
      ),
      company: Company(
        name: company?.str('name') ?? 'PeopleOS',
        timezone: company?.str('timezone') ?? 'Asia/Jakarta',
      ),
      serverLocale: (raw['preferences'] is Map)
          ? (raw['preferences'] as Map)['locale'] as String?
          : null,
      permissions: (raw['permissions'] is List)
          ? (raw['permissions'] as List).map((e) => e.toString()).toList()
          : const [],
    );
  }
}

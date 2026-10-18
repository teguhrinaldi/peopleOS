<?php

namespace App\Support;

/**
 * Single source of truth for every permission slug and the default role matrix.
 * Business code checks permissions (never role names). Roles are only bundles of permissions.
 */
final class PermissionRegistry
{
    public const ADMIN_ACCESS = 'admin.access';

    /** @return array<string, array<string, string>> group => [slug => label] */
    public static function all(): array
    {
        return [
            'Access' => [
                'admin.access' => 'Sign in to the web admin',
                'profile.view' => 'View own profile',
            ],
            'Employees' => [
                'employee.view' => 'View all employees',
                'employee.view_team' => 'View direct reports',
                'employee.create' => 'Create employees',
                'employee.edit' => 'Edit employees',
                'employee.delete' => 'Delete employees',
            ],
            'Organization' => [
                'organization.view' => 'View organization structure',
                'organization.manage' => 'Manage branches, departments, positions',
            ],
            'Attendance' => [
                'attendance.self' => 'Check in/out and view own attendance',
                'attendance.view' => 'View all attendance',
                'attendance.view_team' => 'View team attendance',
                'attendance.manage' => 'Manage schedules, shifts, locations',
                'attendance.correct' => 'Approve corrections for any employee',
                'attendance.approve_team' => 'Approve corrections for direct reports',
                'attendance.review' => 'Review flagged attendance',
                'correction.request' => 'Request attendance corrections',
            ],
            'Overtime' => [
                'overtime.request' => 'Request overtime',
                'overtime.view' => 'View all overtime requests',
                'overtime.approve' => 'Approve overtime for any employee',
                'overtime.approve_team' => 'Approve overtime for direct reports',
                'overtime.manage' => 'Manage overtime pay rules',
            ],
            'Leave' => [
                'leave.request' => 'Request leave and view own balances',
                'leave.view' => 'View all leave requests and balances',
                'leave.view_team' => 'View team leave requests and balances',
                'leave.approve' => 'Approve or reject leave for any employee',
                'leave.approve_team' => 'Approve or reject leave for direct reports',
                'leave.manage' => 'Manage leave types, policies and balance adjustments',
            ],
            'Payroll' => [
                'salary.view' => 'View salary structures and employee salaries',
                'salary.manage' => 'Manage salary structures and employee salaries',
                'payroll.view' => 'View payroll periods and runs',
                'payroll.manage' => 'Create periods, generate and submit payroll',
                'payroll.approve' => 'Approve payroll in review',
                'payroll.finalize' => 'Finalize payroll and mark it paid',
                'payslip.view' => 'View every payslip',
                'payslip.view_own' => 'View own payslips',
            ],
            'Documents' => [
                'document.view' => 'View all employee and company documents',
                'document.view_own' => 'View own documents',
                'document.manage' => 'Upload, archive and delete documents',
            ],
            'Expenses' => [
                'expense.request' => 'Submit and track own reimbursement claims',
                'expense.view' => 'View all reimbursement claims',
                'expense.view_team' => 'View team reimbursement claims',
                'expense.approve' => 'Approve or reject reimbursements for any employee',
                'expense.approve_team' => 'Approve or reject reimbursements for direct reports',
                'expense.pay' => 'Schedule and record reimbursement payments',
                'expense.manage' => 'Manage expense categories, policy and cancel claims',
            ],
            'Reports & Settings' => [
                'report.view' => 'View reports',
                'activity.view' => 'View the audit trail',
                'settings.manage' => 'Manage settings',
            ],
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::all())));
    }

    /** @return array<string, array{name: string, description: string, permissions: list<string>}> */
    public static function roles(): array
    {
        $self = [
            'profile.view', 'attendance.self', 'correction.request', 'overtime.request',
            'leave.request', 'payslip.view_own', 'document.view_own', 'expense.request',
        ];

        $all = self::slugs();

        return [
            'admin' => [
                'name' => 'Admin',
                'description' => 'Full access to every module and setting.',
                'permissions' => $all,
            ],
            'hr' => [
                'name' => 'HR',
                'description' => 'Runs people operations. No system settings.',
                'permissions' => array_values(array_diff($all, ['settings.manage'])),
            ],
            'manager' => [
                'name' => 'Manager',
                'description' => 'Sees and approves for direct reports only.',
                'permissions' => array_merge($self, [
                    'admin.access', 'employee.view_team', 'organization.view', 'attendance.view_team',
                    'attendance.approve_team', 'overtime.approve_team', 'leave.view_team', 'leave.approve_team',
                    'expense.view_team', 'expense.approve_team',
                    'report.view', // reports are scoped to the manager's team by AccessScope
                ]),
            ],
            'employee' => [
                'name' => 'Employee',
                'description' => 'Self-service only.',
                'permissions' => $self,
            ],
        ];
    }
}

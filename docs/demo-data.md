# Demo data

`php artisan db:seed` (or `migrate --seed`) runs, in order: `RolesAndPermissionsSeeder`, `DemoSeeder` (company, people, attendance), `LeaveDemoSeeder`, `PayrollDemoSeeder`, `DocumentDemoSeeder`, `NotificationDemoSeeder` (if present). The demo data is deterministic where it can be, **relative to the day you seed**, and for development only.

For a real installation run only `--class=RolesAndPermissionsSeeder` (and `DocumentTypeSeeder`), then create your own company and admin ([deployment.md](deployment.md)). **Every demo login uses the password `password`; never use these accounts in production.**

## Accounts

| Email | Person | Role | Purpose |
|---|---|---|---|
| admin@peopleos.test | Alex Morgan EMP-0001 | Admin | full web admin; approves payroll prepared by HR; settings |
| hr@peopleos.test | Sarah Wilson EMP-0008 | HR | people, attendance review, leave, prepares payroll, documents, reports (no system settings) |
| manager@peopleos.test | Amanda Clark EMP-0010 | Manager | approves leave, corrections, overtime for Michael, Olivia, Ava |
| kevin@peopleos.test | Kevin Tan EMP-0014 | Manager | Engineering team |
| employee@peopleos.test | Michael Carter EMP-0012 | Employee | the default mobile demo: GPS check-in, leave, payslips, documents |
| olivia@peopleos.test | Olivia Martin EMP-0013 | Employee | contract staff; pending leave, correction and overtime for Amanda |
| jessica@peopleos.test | Jessica Taylor EMP-0019 | Employee | Surabaya site: **GPS + selfie** policy |

## Company and structure

* **PeopleOS Demo Company**, timezone `Asia/Jakarta`, currency IDR (Settings).
* 18 employees: 15 active, 1 inactive (Liam Park), 1 terminated (Noah Davis, left 2026‑07‑31), 1 pending (Ava Thompson, joins 2026‑10‑05).
* Branches: Bandung Head Office, Jakarta Branch, Surabaya Field Office. Departments: HR, Engineering, Finance, Marketing, Operations, Sales. 13 positions.
* Work locations: **Head Office** (Bandung, 150 m, GPS), **Jakarta Office** (120 m, GPS), **Surabaya Field Office** (100 m, **GPS + selfie**).
* Shifts: Regular 08:00–17:00 (10 min grace, unpaid lunch), Afternoon 14:00–22:00, **Night 22:00–06:00** (Christopher Lee works it). Calendar Mon–Fri; a few illustrative public holidays.

## Attendance

21+ days of history for active employees: ~80 % on time, ~16 % late, ~4 % absent, GPS positions inside each site's radius. Demo logins are left un-checked-in today so the flow can be demonstrated. Michael has a forgot-check-out day with a **pending correction**; Olivia has a pending correction and overtime; Michael has approved overtime.

## Leave (`LeaveDemoSeeder`)

Types: Annual (12 days, 6 carry-over, 3 days notice; contract staff 10), Sick (unlimited, may start up to 7 days back), Personal (3 days), Unpaid, Other. Created through the real `LeaveService`, so balances equal their requests:

* Michael: Annual 12 → 3 used, 3 reserved, 6 available (approved August trip, pending late-October request, plus a rejected Personal request).
* Pending requests waiting for managers/HR (Olivia, James Anderson, Michael), approved and rejected examples, one cancelled, an unpaid leave that feeds the payroll deduction, and a half-day Personal leave.

## Payroll (`PayrollDemoSeeder`)

Demo components (illustrative amounts, **not** statutory rules): Basic Salary, Transport and Meal allowance per present day, Position allowance, Overtime per hour, Unpaid leave deduction, "Cooperative Savings" deduction. Structures: Staff and Manager. Salaries are effective-dated; Michael earns 8,000,000 until June 2026 and 9,000,000 from July.

| Period | State | Notes |
|---|---|---|
| July 2026 | PAID | finalized, payslip PDFs generated |
| August 2026 | PAID | finalized, payslip PDFs generated |
| September 2026 | REVIEW | prepared by HR; sign in as Admin to approve (separate-approver rule), then finalize |

Totals shown in the app derive from the stored items (e.g. in the development database July: 16 employees, net 252,020,000 IDR). They vary slightly with the attendance generated on the day you seed.

## Documents (`DocumentDemoSeeder`)

39 demo documents with real small PDFs: contracts, ID references, certificates, and company documents (Employee Handbook, Code of Conduct, Holiday Calendar). States are computed for the seed day: ACTIVE, EXPIRING (within 30 days), EXPIRED, one ARCHIVED. Michael sees a contract, an expiring ID reference, company documents and an expired First Aid certificate, and **cannot** see an HR-only note filed about him.

## Notifications

Seeded through the real flows: payslip available, documents expiring/expired, leave pending/approved. Michael and the managers have unread items for the in-app inbox.

## Flutter Demo Mode

Demo Mode (no backend) ships its own consistent data set implementing the same rules: Michael's profile, 45 days of attendance, leave with the same balances as above, three paid payslips with IDR lines (basic 9,000,000, transport 50,000 and meal 35,000 per present day, overtime, cooperative savings) whose earnings minus deductions equal net, documents with statuses computed from the device date (HR-only items hidden), and notifications.

## Re-seeding

`php artisan migrate:fresh --seed` resets everything. **Never run it against data you want to keep.**

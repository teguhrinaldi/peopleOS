<?php

namespace Tests\Feature\Admin;

use App\Enums\EmployeeStatus;
use App\Models\Activity;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class EmployeeCrudTest extends AdminTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'emp-5001', 'full_name' => 'Nina Novak', 'gender' => 'female', 'date_of_birth' => '1994-04-12',
            'phone' => '+62 812 5555 0101', 'email' => 'Nina.Novak@Corp.Example', 'address' => 'Jl. Melati 1',
            'join_date' => '2026-09-01', 'employment_type' => 'permanent', 'status' => 'ACTIVE',
            'department_id' => $this->department->id, 'position_id' => $this->position->id,
            'work_location_id' => $this->location->id, 'shift_id' => $this->shift->id,
        ], $overrides);
    }

    public function test_hr_creates_an_employee_with_a_first_dated_assignment_and_an_audit_row(): void
    {
        [$hr] = $this->signIn('hr');
        [, $boss] = $this->makeStaff('manager');

        $response = $this->post('/admin/employees', $this->payload(['manager_id' => $boss->id, 'assignment_reason' => 'Initial']));

        $employee = Employee::query()->where('employee_number', 'EMP-5001')->firstOrFail();
        $response->assertRedirect("/admin/employees/{$employee->id}");

        $this->assertSame('nina.novak@corp.example', $employee->email);
        $this->assertSame(EmployeeStatus::Active, $employee->status);
        $assignment = $employee->assignments()->firstOrFail();
        $this->assertSame('2026-09-01', $assignment->effective_from->toDateString());
        $this->assertSame($boss->id, $assignment->manager_id);
        $this->assertSame($this->branch->id, $assignment->branch_id, 'branch defaults from the department');
        $this->assertNull($employee->user_id);

        $log = Activity::query()->where('action', 'employee.created')->where('entity_id', $employee->id)->firstOrFail();
        $this->assertSame($hr->id, $log->actor_id);
    }

    public function test_create_validates_required_fields(): void
    {
        $this->signIn('hr');

        $this->post('/admin/employees', [])->assertSessionHasErrors(['employee_number', 'full_name', 'join_date', 'employment_type', 'status', 'department_id', 'position_id']);
        $this->assertSame(1, Employee::query()->count(), 'only the signed-in HR employee exists');
    }

    public function test_duplicate_employee_number_and_email_show_friendly_field_errors(): void
    {
        $this->signIn('hr');
        $this->person('Existing Eve', ['employee_number' => 'EMP-5001', 'email' => 'eve@corp.example']);

        $this->post('/admin/employees', $this->payload(['employee_number' => 'emp-5001', 'email' => 'fresh@corp.example']))
            ->assertSessionHasErrors(['employee_number' => 'This employee number is already in use.']);

        $this->post('/admin/employees', $this->payload(['employee_number' => 'EMP-5002', 'email' => 'EVE@corp.example']))
            ->assertSessionHasErrors(['email' => 'This email address is already in use.']);

        $this->assertSame(1, Employee::query()->where('employee_number', 'like', 'EMP-500%')->count());
    }

    public function test_login_email_must_also_be_free_in_users(): void
    {
        $this->signIn('hr');
        User::query()->create(['name' => 'Squatter', 'email' => 'nina.novak@corp.example', 'password' => 'x', 'is_active' => true]);

        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'employee', 'password' => 'Str0ngPassw0rd']))
            ->assertSessionHasErrors('email');
    }

    public function test_optional_login_is_created_with_role_and_hashed_password(): void
    {
        $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'manager', 'password' => 'Str0ngPassw0rd']))->assertRedirect();

        $employee = Employee::query()->where('employee_number', 'EMP-5001')->firstOrFail();
        $user = $employee->user;
        $this->assertNotNull($user);
        $this->assertSame('nina.novak@corp.example', $user->email);
        $this->assertTrue(Hash::check('Str0ngPassw0rd', $user->password));
        $this->assertTrue($user->hasPermission('attendance.approve_team'));
        $this->assertTrue($user->is_active);

        $audit = Activity::query()->where('action', 'employee.created')->firstOrFail();
        $this->assertStringNotContainsString('Str0ngPassw0rd', json_encode($audit->new_values));
    }

    public function test_a_login_for_a_pending_employee_stays_disabled_until_activation(): void
    {
        [$hr] = $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['status' => 'PENDING', 'create_login' => 1, 'role_slug' => 'employee', 'password' => 'Str0ngPassw0rd']));

        $employee = Employee::query()->where('employee_number', 'EMP-5001')->firstOrFail();
        $this->assertFalse($employee->user->fresh()->is_active);

        $this->post("/admin/employees/{$employee->id}/status", ['status' => 'ACTIVE'])->assertRedirect();
        $this->assertTrue($employee->user->fresh()->is_active);
    }

    public function test_login_requires_role_and_a_strong_password(): void
    {
        $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['create_login' => 1]))->assertSessionHasErrors(['role_slug', 'password']);
        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'employee', 'password' => 'short']))->assertSessionHasErrors('password');
        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'employee', 'password' => 'onlyletterspassword']))->assertSessionHasErrors('password');
    }

    public function test_hr_cannot_hand_out_a_more_powerful_role_than_their_own(): void
    {
        $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'admin', 'password' => 'Str0ngPassw0rd']))
            ->assertSessionHasErrors(['role_slug' => 'You cannot assign that role.']);
        $this->assertSame(0, Employee::query()->where('employee_number', 'EMP-5001')->count());

        $this->signIn('admin');
        $this->post('/admin/employees', $this->payload(['create_login' => 1, 'role_slug' => 'admin', 'password' => 'Str0ngPassw0rd']))->assertSessionHasNoErrors();
        $this->assertTrue(Employee::query()->where('employee_number', 'EMP-5001')->firstOrFail()->user->hasPermission('settings.manage'));
    }

    public function test_client_supplied_ids_are_checked_against_the_company_and_liveness(): void
    {
        $this->signIn('hr');
        $terminated = $this->person('Gone Gary', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);

        $this->post('/admin/employees', $this->payload(['department_id' => 99999, 'position_id' => 99999, 'shift_id' => 99999, 'work_location_id' => 99999, 'branch_id' => 99999]))
            ->assertSessionHasErrors(['department_id', 'position_id', 'shift_id', 'work_location_id', 'branch_id']);
        $this->post('/admin/employees', $this->payload(['manager_id' => $terminated->id]))->assertSessionHasErrors('manager_id');
    }

    public function test_photo_upload_is_stored_under_a_random_name_and_validated(): void
    {
        Storage::fake('public');
        $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['photo' => UploadedFile::fake()->image('../../evil name.jpg', 200, 200)]))->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'EMP-5001')->firstOrFail();
        $this->assertNotNull($employee->photo_path);
        $this->assertStringStartsWith('employees/', $employee->photo_path);
        $this->assertStringNotContainsString('evil', $employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);
    }

    public function test_photo_rules_reject_large_and_non_image_files(): void
    {
        Storage::fake('public');
        $this->signIn('hr');

        $this->post('/admin/employees', $this->payload(['photo' => UploadedFile::fake()->image('big.jpg')->size(3000)]))->assertSessionHasErrors('photo');
        $this->post('/admin/employees', $this->payload(['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('photo');
        $this->post('/admin/employees', $this->payload(['photo' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')]))->assertSessionHasErrors('photo');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_update_saves_profile_fields_logs_the_diff_and_replaces_the_photo(): void
    {
        Storage::fake('public');
        [$hr] = $this->signIn('hr');
        $employee = $this->person('Old Name', ['employee_number' => 'EMP-6001', 'photo_path' => UploadedFile::fake()->image('a.jpg')->store('employees', 'public')]);
        $oldPhoto = $employee->photo_path;

        $this->put("/admin/employees/{$employee->id}", [
            'employee_number' => 'EMP-6001', 'full_name' => 'New Name', 'email' => $employee->email, 'join_date' => '2025-01-01',
            'employment_type' => 'contract', 'photo' => UploadedFile::fake()->image('b.png'),
        ])->assertRedirect("/admin/employees/{$employee->id}");

        $employee->refresh();
        $this->assertSame('New Name', $employee->full_name);
        $this->assertSame('contract', $employee->employment_type->value);
        $this->assertNotSame($oldPhoto, $employee->photo_path);
        Storage::disk('public')->assertMissing($oldPhoto);
        Storage::disk('public')->assertExists($employee->photo_path);

        $log = Activity::query()->where('action', 'employee.updated')->where('entity_id', $employee->id)->firstOrFail();
        $this->assertSame('New Name', $log->new_values['full_name']);
        $this->assertSame($hr->id, $log->actor_id);
    }

    public function test_update_allows_keeping_own_number_and_email_but_not_taking_anothers(): void
    {
        $this->signIn('hr');
        $a = $this->person('Person A', ['employee_number' => 'EMP-6101', 'email' => 'a@corp.example']);
        $b = $this->person('Person B', ['employee_number' => 'EMP-6102', 'email' => 'b@corp.example']);
        $base = ['full_name' => 'Person B', 'join_date' => '2025-01-01', 'employment_type' => 'permanent'];

        $this->put("/admin/employees/{$b->id}", $base + ['employee_number' => 'EMP-6102', 'email' => 'b@corp.example'])->assertSessionHasNoErrors();
        $this->put("/admin/employees/{$b->id}", $base + ['employee_number' => 'EMP-6101', 'email' => 'b@corp.example'])->assertSessionHasErrors('employee_number');
        $this->put("/admin/employees/{$b->id}", $base + ['employee_number' => 'EMP-6102', 'email' => 'a@corp.example'])->assertSessionHasErrors('email');
        $this->assertSame('EMP-6102', $b->fresh()->employee_number);
        $this->assertNotNull($a);
    }

    public function test_remove_photo_clears_the_path_and_deletes_the_file(): void
    {
        Storage::fake('public');
        $this->signIn('hr');
        $employee = $this->person('Pic Pia', ['photo_path' => UploadedFile::fake()->image('a.jpg')->store('employees', 'public')]);
        $file = $employee->photo_path;

        $this->put("/admin/employees/{$employee->id}", [
            'employee_number' => $employee->employee_number, 'full_name' => 'Pic Pia', 'email' => $employee->email,
            'join_date' => '2025-01-01', 'employment_type' => 'permanent', 'remove_photo' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNull($employee->fresh()->photo_path);
        Storage::disk('public')->assertMissing($file);
    }

    public function test_terminated_employees_cannot_be_edited(): void
    {
        $this->signIn('hr');
        $gone = $this->person('Gone Gary', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);

        $this->get("/admin/employees/{$gone->id}/edit")->assertRedirect("/admin/employees/{$gone->id}")->assertSessionHas('error');
        $this->from("/admin/employees/{$gone->id}")->put("/admin/employees/{$gone->id}", [
            'employee_number' => $gone->employee_number, 'full_name' => 'Renamed', 'email' => $gone->email, 'join_date' => '2025-01-01', 'employment_type' => 'permanent',
        ])->assertSessionHas('error');

        $this->assertSame('Gone Gary', $gone->fresh()->full_name);
    }

    public function test_the_forms_render(): void
    {
        $this->signIn('hr');
        $employee = $this->person('Formal Fay');

        $this->get('/admin/employees/create')->assertOk()->assertSee('Add employee')->assertSee('Create a login for this employee');
        $this->get("/admin/employees/{$employee->id}/edit")->assertOk()->assertSee('Formal Fay');
    }
}

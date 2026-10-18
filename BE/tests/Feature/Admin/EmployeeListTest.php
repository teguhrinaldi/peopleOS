<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class EmployeeListTest extends AdminTestCase
{
    public function test_hr_sees_everyone_including_terminated_and_pending(): void
    {
        $this->signIn('hr');
        $this->person('Active Ann');
        $this->person('Pending Pat', ['status' => 'PENDING']);
        $this->person('Gone Gary', ['status' => 'TERMINATED', 'terminated_at' => '2026-06-30']);

        $this->get('/admin/employees')->assertOk()
            ->assertSee('Active Ann')->assertSee('Pending Pat')->assertSee('Gone Gary');
    }

    public function test_a_manager_only_sees_direct_reports(): void
    {
        [, $manager] = $this->signIn('manager');
        $mine = $this->person('Report Rita', [], ['manager_id' => $manager->id]);
        $this->person('Stranger Stan');
        [, $otherManager] = $this->makeStaff('manager');
        $this->person('Other Report Olga', [], ['manager_id' => $otherManager->id]);

        $response = $this->get('/admin/employees')->assertOk();

        $response->assertSee('Report Rita')->assertDontSee('Stranger Stan')->assertDontSee('Other Report Olga');
        $this->assertSame([$mine->id], $response->viewData('employees')->pluck('id')->all());
    }

    public function test_a_manager_can_open_a_report_but_not_someone_elses_detail(): void
    {
        [, $manager] = $this->signIn('manager');
        $report = $this->person('Report Rita', [], ['manager_id' => $manager->id]);
        $stranger = $this->person('Stranger Stan');

        $this->get("/admin/employees/{$report->id}")->assertOk()->assertSee('Report Rita');
        $this->get("/admin/employees/{$stranger->id}")->assertForbidden();
        $this->get("/admin/employees/{$stranger->id}?tab=activity")->assertForbidden();
    }

    public function test_a_former_report_is_no_longer_visible_to_the_old_manager(): void
    {
        [, $manager] = $this->signIn('manager');
        [, $newManager] = $this->makeStaff('manager');
        $report = $this->person('Moved Mia', [], ['manager_id' => $manager->id, 'effective_from' => '2025-01-01', 'effective_to' => '2026-08-31']);
        $report->assignments()->create([
            'department_id' => $this->department->id, 'position_id' => $this->position->id, 'manager_id' => $newManager->id, 'effective_from' => '2026-09-01',
        ]);

        $this->get("/admin/employees/{$report->id}")->assertForbidden();
    }

    public function test_managers_cannot_edit_delete_or_add_employees_even_for_their_reports(): void
    {
        [, $manager] = $this->signIn('manager');
        $report = $this->person('Report Rita', [], ['manager_id' => $manager->id]);

        $this->get("/admin/employees/{$report->id}/edit")->assertForbidden();
        $this->put("/admin/employees/{$report->id}", ['full_name' => 'Hacked'])->assertForbidden();
        $this->get("/admin/employees/{$report->id}")->assertOk()->assertDontSee('Terminate employment');
    }

    public function test_search_matches_name_number_and_email(): void
    {
        $this->signIn('hr');
        $this->person('Alice Wonder', ['employee_number' => 'EMP-7001', 'email' => 'alice@corp.example']);
        $this->person('Bob Builder', ['employee_number' => 'EMP-7002', 'email' => 'bob@corp.example']);

        $this->get('/admin/employees?q=wonder')->assertSee('Alice Wonder')->assertDontSee('Bob Builder');
        $this->get('/admin/employees?q=EMP-7002')->assertSee('Bob Builder')->assertDontSee('Alice Wonder');
        $this->get('/admin/employees?q=bob@corp')->assertSee('Bob Builder')->assertDontSee('Alice Wonder');
        $this->get('/admin/employees?q=100%25')->assertOk()->assertSee('No employees match');
    }

    public function test_filters_by_status_department_position_location_and_type(): void
    {
        $this->signIn('hr');
        $sales = Department::query()->create(['company_id' => $this->company->id, 'code' => 'SAL', 'name' => 'Sales']);
        $this->person('Mkt Mike');
        $this->person('Sales Sue', ['employment_type' => 'contract'], ['department_id' => $sales->id]);
        $this->person('Pending Pete', ['status' => 'PENDING']);

        $this->get('/admin/employees?department_id='.$sales->id)->assertSee('Sales Sue')->assertDontSee('Mkt Mike');
        $this->get('/admin/employees?status=PENDING')->assertSee('Pending Pete')->assertDontSee('Sales Sue');
        $this->get('/admin/employees?employment_type=contract')->assertSee('Sales Sue')->assertDontSee('Mkt Mike');
        $this->get('/admin/employees?position_id='.$this->position->id.'&work_location_id='.$this->location->id)->assertSee('Mkt Mike')->assertSee('Sales Sue');
    }

    public function test_sorting_and_invalid_sort_values(): void
    {
        $this->signIn('hr');
        $this->person('Zed Zulu', ['join_date' => '2024-01-01']);
        $this->person('Amy Adams', ['join_date' => '2025-06-01']);

        $names = fn (string $qs) => $this->get('/admin/employees'.$qs)->assertOk()->viewData('employees')->pluck('full_name')->values()->all();
        $asc = array_values(array_intersect($names('?sort=name&dir=asc'), ['Amy Adams', 'Zed Zulu']));
        $desc = array_values(array_intersect($names('?sort=name&dir=desc'), ['Amy Adams', 'Zed Zulu']));
        $this->assertSame(['Amy Adams', 'Zed Zulu'], $asc);
        $this->assertSame(['Zed Zulu', 'Amy Adams'], $desc);
        $this->get('/admin/employees?sort=department&dir=asc')->assertOk();
        $this->get('/admin/employees?sort=position')->assertOk();
        $this->get('/admin/employees?sort=password;drop')->assertSessionHasErrors('sort');
    }

    public function test_pagination_is_25_per_page_and_keeps_the_query_string(): void
    {
        $this->signIn('hr');
        foreach (range(1, 30) as $i) {
            $this->person(sprintf('Bulk Person %02d', $i), ['employee_number' => sprintf('BULK-%02d', $i)]);
        }

        $page1 = $this->get('/admin/employees?q=Bulk&sort=name')->assertOk();
        $this->assertCount(25, $page1->viewData('employees')->items());
        $page1->assertSee('q=Bulk', false)->assertSee('page=2', false);

        $page2 = $this->get('/admin/employees?q=Bulk&sort=name&page=2')->assertOk();
        $this->assertCount(5, $page2->viewData('employees')->items());
        $this->assertSame(30, $page2->viewData('employees')->total());
    }

    public function test_the_list_avoids_n_plus_one_queries(): void
    {
        $this->signIn('hr');
        foreach (range(1, 20) as $i) {
            $this->person("Perf Person {$i}");
        }

        DB::enableQueryLog();
        $this->get('/admin/employees')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(30, $count, "Employee list ran {$count} queries");
        $this->assertGreaterThan(20, Employee::query()->count());
    }

    public function test_the_detail_page_tabs_respect_permissions(): void
    {
        [, $manager] = $this->signIn('manager');
        $report = $this->person('Report Rita', [], ['manager_id' => $manager->id]);

        $this->get("/admin/employees/{$report->id}")->assertOk()
            ->assertSee('Attendance')->assertDontSee('Payroll')->assertDontSee('Documents')->assertDontSee('Activity');
        $this->get("/admin/employees/{$report->id}?tab=payroll")->assertOk()->assertDontSee('Payroll arrives');

        $this->signIn('hr');
        $this->get("/admin/employees/{$report->id}?tab=payroll")->assertOk()->assertSee('Current salary')->assertSee('No tax or statutory rules are built in');
        $this->get("/admin/employees/{$report->id}?tab=attendance")->assertOk()->assertSee('No attendance yet');
        $this->get("/admin/employees/{$report->id}?tab=activity")->assertOk()->assertSee('Activity');
    }
}

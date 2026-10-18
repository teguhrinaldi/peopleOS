<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Document;
use App\Services\Documents\DocumentTypeService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentFixtures;

/**
 * The custom listbox replaces every native select popup, while the native <select> stays the real form control
 * (so GET filters and form posts keep working without JS). Filters must change the backend query within scope.
 */
class DropdownFiltersTest extends AdminTestCase
{
    use DocumentFixtures;

    /** Every <select> on these pages is wrapped by the listbox component (no bare native popups left). */
    public function test_every_select_on_admin_pages_is_enhanced(): void
    {
        [$admin] = $this->signIn('admin');
        Storage::fake('local');
        app(DocumentTypeService::class)->installDefaults();
        $employee = $this->person('Sel Ect');
        $pages = ['/admin/employees', '/admin/employees/create', "/admin/employees/{$employee->id}/edit", '/admin/attendance', '/admin/leave-requests?tab=history',
            '/admin/payroll', '/admin/payslips', '/admin/documents', '/admin/organization', '/admin/settings', '/admin/shifts/assignments',
            '/admin/reports/attendance.daily', '/admin/salary-structures/create', '/admin/leave-types/create', '/admin/work-locations', '/admin/'];

        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $selects = preg_match_all('/<select\b/', $html);
            $wrapped = preg_match_all('/x-data="listbox"/', $html);
            $this->assertSame($selects, $wrapped, "{$url}: {$selects} selects but {$wrapped} listboxes");
        }
        $this->assertNotNull($admin);
    }

    public function test_the_component_renders_an_accessible_combobox_around_the_native_select(): void
    {
        $this->signIn('admin');
        $dept = Department::query()->create(['company_id' => $this->company->id, 'code' => 'FIN', 'name' => 'Finance']);
        $html = $this->get('/admin/employees?department_id='.$dept->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div x-data="listbox" class="lb relative">\s*<select id="f-department-id" name="department_id"/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$dept->id.'"\s+selected/', $html, 'the selected value survives the request');
        $this->assertStringContainsString('role="combobox" aria-haspopup="listbox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('class="ctl lb-trigger"', $html);
    }

    public function test_employee_filters_really_change_the_query(): void
    {
        [, $me] = $this->signIn('admin');
        $finance = Department::query()->create(['company_id' => $this->company->id, 'code' => 'FIN', 'name' => 'Finance']);
        $night = $this->makeShift('NGT', '22:00', '06:00');
        $boss = $this->person('Boss Bea');
        $a = $this->person('Anna Finance', [], ['department_id' => $finance->id, 'manager_id' => $boss->id]);
        $b = $this->person('Ben Night', ['employment_type' => 'contract'], ['shift_id' => $night->id]);
        $c = $this->person('Cid Inactive', ['status' => 'INACTIVE']);

        $see = fn (array $q) => $this->get('/admin/employees?'.http_build_query($q))->assertOk();

        $see(['department_id' => $finance->id])->assertSee('Anna Finance')->assertDontSee('Ben Night');
        $see(['manager_id' => $boss->id])->assertSee('Anna Finance')->assertDontSee('Ben Night')->assertDontSee('Cid Inactive');
        $see(['shift_id' => $night->id])->assertSee('Ben Night')->assertDontSee('Anna Finance');
        $see(['employment_type' => 'contract'])->assertSee('Ben Night')->assertDontSee('Anna Finance');
        $see(['status' => 'INACTIVE'])->assertSee('Cid Inactive')->assertDontSee('Anna Finance');
        $see(['work_location_id' => $this->location->id, 'department_id' => $finance->id])->assertSee('Anna Finance');
        $see(['department_id' => $finance->id, 'shift_id' => $night->id])->assertSee('No employees match these filters')->assertSee('Clear filters');
        $this->get('/admin/employees?status=NOPE')->assertSessionHasErrors('status');
        $this->assertNotNull($me);

        // The manager filter only offers people who manage someone, and the listbox shows the chosen one.
        $html = $see(['manager_id' => $boss->id])->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$boss->id.'"\s+selected/', $html);
        $this->assertNotNull($a);
        $this->assertNotNull($c);
    }

    public function test_filters_stay_inside_the_permission_scope(): void
    {
        [, $me] = $this->signIn('manager');
        $mine = $this->person('Team Tom', [], ['manager_id' => $me->id]);
        $other = $this->person('Stranger Stan', [], ['department_id' => $this->department->id]);

        $html = $this->get('/admin/employees?department_id='.$this->department->id)->assertOk()->getContent();
        $this->assertStringContainsString('Team Tom', $html);
        $this->assertStringNotContainsString('Stranger Stan', $html, 'a filter never widens the scope');
        $this->assertNotNull($mine);
        $this->assertNotNull($other);
    }

    public function test_leave_payroll_and_document_filters(): void
    {
        $this->signIn('admin');
        Storage::fake('local');
        app(DocumentTypeService::class)->installDefaults();
        [$this->hr] = $this->makeStaff('hr');
        $p = $this->person('Filter Fay');
        $type = $this->leaveType();
        $this->leaveFor($p, $type, '2026-10-05');

        $this->get('/admin/leave-requests?tab=history&status=PENDING')->assertOk()->assertSee('Filter Fay');
        $this->get('/admin/leave-requests?tab=history&status=REJECTED')->assertOk()->assertDontSee('Filter Fay');
        $this->get('/admin/leave-requests?tab=history&leave_type_id='.$type->id)->assertOk()->assertSee('Filter Fay');

        $this->makeDocument($p, ['title' => 'Contract Fay']);
        $this->makeDocument(null, ['title' => 'Handbook']);
        $this->get('/admin/documents?scope=company')->assertOk()->assertSee('Handbook')->assertDontSee('Contract Fay');
        $this->get('/admin/documents?document_type_id='.$this->type('EMPLOYMENT_CONTRACT')->id)->assertOk()->assertSee('Contract Fay')->assertDontSee('Handbook');
        $this->assertSame(2, Document::query()->count());

        $this->get('/admin/payroll?status=PAID')->assertOk()->assertSee('No periods match');
        $this->get('/admin/attendance?date=2026-09-30&department_id='.$this->department->id)->assertOk();
    }
}

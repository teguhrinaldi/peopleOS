<?php

namespace Tests\Feature\Admin;

use App\Models\Payslip;
use Illuminate\Support\Facades\DB;

/**
 * Data-provenance audit, performance part: list pages must not issue one query per row (N+1).
 * Each page is measured with a small and a larger data set; the query count may not grow with the rows.
 */
class QueryBudgetTest extends Batch2TestCase
{
    private function queries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_list_pages_do_not_grow_queries_with_rows(): void
    {
        $this->actingAs($this->hr);
        $a = $this->person('Row One');
        $this->giveSalary($a, '5000000');
        $this->makeDocument($a);
        $this->makeDocument(null);
        $period = $this->toFinalized($this->period());

        $urls = ['/admin/documents', '/admin/payroll', '/admin/payslips', '/admin/payroll/'.$period->id, '/admin/employees', '/admin/salary-structures/components', '/admin/notifications', '/admin/'];
        $before = array_map(fn ($u) => $this->queries($u), $urls);

        foreach (range(1, 8) as $i) {
            $p = $this->person("Row More {$i}");
            $this->giveSalary($p, '5000000');
            $this->makeDocument($p);
            $this->makeDocument(null);
        }
        $second = $this->toFinalized($this->period('2026-08-01', '2026-08-31'));
        $this->assertGreaterThan(5, Payslip::query()->where('payroll_period_id', $second->id)->count());
        $urls[3] = '/admin/payroll/'.$second->id;
        $after = array_map(fn ($u) => $this->queries($u), $urls);

        foreach ($urls as $i => $url) {
            $this->assertLessThanOrEqual($before[$i] + 3, $after[$i], "{$url}: {$before[$i]} queries with 1 row, {$after[$i]} with 9");
        }
    }
}

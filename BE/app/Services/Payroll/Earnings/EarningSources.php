<?php

namespace App\Services\Payroll\Earnings;

use App\Services\Payroll\Contracts\PayrollEarningSource;
use InvalidArgumentException;

/** The registered earning sources (config/payroll.php `earning_sources`). */
class EarningSources
{
    /** @return list<PayrollEarningSource> every registered source (enabled or not: hooks must still release/freeze) */
    public function all(): array
    {
        $out = [];
        foreach ((array) config('payroll.earning_sources', []) as $class) {
            $source = app($class);
            if (! $source instanceof PayrollEarningSource) {
                throw new InvalidArgumentException("{$class} must implement PayrollEarningSource.");
            }
            $out[] = $source;
        }

        return $out;
    }

    /** @return list<PayrollEarningSource> */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (PayrollEarningSource $s) => $s->enabled()));
    }

    public function find(string $key): ?PayrollEarningSource
    {
        foreach ($this->all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }
}

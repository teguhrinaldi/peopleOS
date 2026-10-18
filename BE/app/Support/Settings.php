<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Typed company settings stored as key/value rows in `settings`.
 *
 * DEFINITIONS is the only list of configurable keys: a key that is not declared here cannot be read
 * or written, so typos fail loudly and every value has a documented default. Company identity and
 * timezone live on the `companies` row; everything else configurable lives here.
 */
class Settings
{
    /** key => [type, default, label] */
    public const DEFINITIONS = [
        'general.currency' => ['string', 'IDR', 'Currency (ISO 4217)'],
        'general.date_format' => ['string', 'd M Y', 'Date display format'],
        'general.locale' => ['string', 'en', 'Default language (en or id) for users without a preference'],
        'general.week_start' => ['int', 1, 'First day of the week (1 = Monday, 7 = Sunday)'],
        'general.number_format' => ['string', 'auto', 'Number format: auto (by language), or us, id'],
        'employee.number_prefix' => ['string', 'EMP-', 'Employee number prefix'],
        'employee.number_digits' => ['int', 4, 'Employee number digits'],
        'leave.year_start_month' => ['int', 1, 'Leave year starts in month (1-12)'],
        'payroll.require_separate_approver' => ['bool', true, 'Payroll approver must differ from the preparer'],
        'payroll.payslip_prefix' => ['string', 'PS', 'Payslip number prefix'],
        'payroll.rounding_decimals' => ['string', 'auto', 'Payroll rounding decimals: auto (by currency), 0, 1 or 2'],
        'overtime.engine_enabled' => ['bool', false, 'Pay overtime through the overtime rule engine (replaces PER_OVERTIME_HOUR components)'],
        'overtime.require_attendance_verification' => ['bool', true, 'Pay overtime only when attendance confirms it (otherwise approved minutes are paid)'],
        'overtime.carry_over_late_approvals' => ['bool', true, 'Pay overtime approved after its period was finalized in the next regular period'],
        'documents.expiring_days' => ['int', 30, 'Days before expiry a document counts as expiring'],
    ];

    private const CACHE_KEY = 'peopleos.settings';

    public function __construct(private readonly CompanyContext $context) {}

    public function get(string $key): mixed
    {
        [$type, $default] = $this->definition($key);
        $all = $this->all();

        return array_key_exists($key, $all) ? $this->cast($type, $all[$key]) : $default;
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            [$type] = $this->definition($key);
            Setting::query()->updateOrCreate(
                ['company_id' => $this->context->id(), 'key' => $key],
                ['value' => $type === 'bool' ? ($value ? '1' : '0') : (string) $value],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> every declared key with its effective value */
    public function effective(): array
    {
        $out = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** Next free employee number, e.g. EMP-0043. Uniqueness is still enforced by the database. */
    public function nextEmployeeNumber(int $sequence): string
    {
        return $this->string('employee.number_prefix').str_pad((string) $sequence, $this->int('employee.number_digits'), '0', STR_PAD_LEFT);
    }

    /** @return array<string, string|null> */
    private function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => Setting::query()
            ->where('company_id', $this->context->id())
            ->pluck('value', 'key')
            ->all());
    }

    /** @return array{0: string, 1: mixed, 2: string} */
    private function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    private function cast(string $type, ?string $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => $value === '1' || $value === 'true',
            default => (string) $value,
        };
    }
}

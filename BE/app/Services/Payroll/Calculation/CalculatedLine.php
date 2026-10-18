<?php

namespace App\Services\Payroll\Calculation;

use App\Enums\SalaryComponentKind;
use App\Services\Payroll\Earnings\EarningLine;

final class CalculatedLine
{
    public function __construct(
        public readonly ?int $componentId,
        public readonly string $code,
        public readonly string $name,
        public readonly SalaryComponentKind $kind,
        public readonly string $calculation,
        public readonly string $amount,
        public readonly ?string $rate = null,
        public readonly ?string $quantity = null,
        public readonly ?string $unitAmount = null,
        public readonly ?string $baseAmount = null,
        public readonly ?string $description = null,
        public readonly ?int $adjustmentId = null,
        public readonly ?string $adjustmentReason = null,
        public readonly int $sortOrder = 0,
        public readonly ?EarningLine $source = null,
    ) {}

    /** @return array<string, mixed> column => value for payroll_item_lines */
    public function toRow(): array
    {
        return [
            'salary_component_id' => $this->componentId,
            'payroll_adjustment_id' => $this->adjustmentId,
            'component_code' => $this->code,
            'component_name' => $this->name,
            'kind' => $this->kind->value,
            'calculation' => $this->calculation,
            'rate' => $this->rate,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
            'base_amount' => $this->baseAmount,
            'amount' => $this->amount,
            'description' => $this->description,
            'adjustment_reason' => $this->adjustmentReason,
            'sort_order' => $this->sortOrder,
            'source_key' => $this->source?->sourceKey,
            'source_refs' => $this->source ? json_encode($this->source->refs) : null,
            'minutes' => $this->source?->minutes,
            'multiplier' => $this->source?->multiplier,
        ];
    }
}

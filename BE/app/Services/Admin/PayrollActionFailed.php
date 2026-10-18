<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** A payroll action the user can understand and fix. The controller turns it into a flash message plus a list. */
class PayrollActionFailed extends RuntimeException
{
    /** @param list<string> $items */
    public function __construct(string $message, public readonly array $items = [], ?ApiException $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request): RedirectResponse
    {
        return back()->withInput()->with('error', $this->getMessage())->with('error_items', $this->items);
    }
}

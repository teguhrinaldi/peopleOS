<?php

namespace App\Services\Payroll\Earnings;

use RuntimeException;

/** A referenced source record changed (or was included elsewhere) between collection and storage: rebuild. */
final class EarningSourceConflict extends RuntimeException {}

<?php
// Marketing-recording only (scratch copy of BE, never in the product): shift the clock by MKT_OFFSET_SEC.
$f = dirname(__DIR__, 2) . '/offset.txt'; $off = is_file($f) ? trim(file_get_contents($f)) : getenv('MKT_OFFSET_SEC');
if ($off !== false && $off !== '' && class_exists(\Carbon\Carbon::class)) {
    $t = new \DateTimeImmutable('@' . (time() + (int) $off));
    \Carbon\Carbon::setTestNow($t);
    \Carbon\CarbonImmutable::setTestNow($t);
}

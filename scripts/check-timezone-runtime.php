<?php

/**
 * Blocking runtime compatibility check for Morocco's legal time (F-02).
 *
 *   php scripts/check-timezone-runtime.php
 *
 * Standalone (no framework, no database, no network). Exit code 0 = this PHP runtime implements Morocco's
 * legal time; exit code 1 = it does not. Run it:
 *   - in CI, BEFORE the test suite (so an outdated timezone database fails with a clear message instead of
 *     silently shifting every Moroccan business date by one hour);
 *   - in the web AND worker containers after any image/PHP/tzdata change (see DEPLOYMENT_CHECKLIST.md).
 *
 * Legal history it encodes (Decree 2.26.530 of 25 June 2026, repealing Decree 2.18.855 of 2018; IANA tz 2026c+):
 *   UTC+1 (summer 2026) -> UTC+0 during Ramadan 2026 -> UTC+1 -> on 2026-09-20 at 02:00 local clocks went back
 *   60 minutes -> UTC+0 (GMT) permanently, no Ramadan change in 2027.
 * The expected offsets are LEGAL FACTS, never derived from the runtime. If Morocco changes its legal time
 * again, update this table and tests/Unit/MoroccoLegalTimeTest.php together, after reading the new decree.
 */
$zone = new DateTimeZone('Africa/Casablanca');

/** @var list<array{0: string, 1: int, 2: string}> UTC instant, expected offset in seconds, why */
$expectations = [
    ['2026-07-15 12:00', 3600, 'summer 2026 is UTC+1 (history is never rewritten to UTC+0)'],
    ['2026-02-20 12:00', 0, 'Ramadan 2026 is UTC+0'],
    ['2026-03-22 12:00', 3600, 'after 22 March 2026: UTC+1'],
    ['2026-09-19 12:00', 3600, 'day before the 2026-09-20 rollback: UTC+1'],
    ['2026-09-20 12:00', 0, 'from 2026-09-20: GMT'],
    ['2026-11-17 12:00', 0, 'November 2026: GMT'],
    ['2026-12-31 23:30', 0, '31 December 2026 23:30 UTC is 23:30 in Casablanca'],
    ['2027-01-01 00:30', 0, '1 January 2027 00:30 UTC is 00:30 in Casablanca'],
    ['2027-02-10 12:00', 0, 'Ramadan 2027: no clock change'],
    ['2027-03-15 12:00', 0, 'after Ramadan 2027: still GMT (obsolete rules predict UTC+1)'],
    ['2027-07-15 12:00', 0, 'summer 2027: GMT'],
];

$failures = [];
foreach ($expectations as [$utc, $expected, $why]) {
    $actual = $zone->getOffset(new DateTimeImmutable($utc.':00', new DateTimeZone('UTC')));
    if ($actual !== $expected) {
        $failures[] = sprintf('  %s Z: expected UTC%+d, runtime says UTC%+d - %s', $utc, $expected / 3600, $actual / 3600, $why);
    }
}
$phantom = array_slice($zone->getTransitions(strtotime('2027-01-01 UTC'), strtotime('2028-01-01 UTC')), 1);
if ($phantom !== []) {
    $failures[] = '  clock changes predicted in 2027 although none exists: '.implode(', ', array_map(fn ($t) => $t['time'], $phantom));
}

printf("php=%s os=%s tzdb=%s extension[timezonedb]=%s\n", PHP_VERSION, PHP_OS_FAMILY, timezone_version_get(), extension_loaded('timezonedb') ? 'loaded' : 'no');

if ($failures !== []) {
    fwrite(STDERR, "FAIL: this PHP runtime does NOT implement Morocco's legal time (Decree 2.26.530, IANA tz 2026c or newer).\n");
    fwrite(STDERR, implode("\n", $failures)."\n");
    fwrite(STDERR, "Fix: use a PHP build/image with tzdata >= 2026c, or install the pinned PECL timezonedb (see .github/workflows/ci.yml and DEPLOYMENT_CHECKLIST.md).\n");
    exit(1);
}

echo 'OK: '.count($expectations)." probes match Morocco's legal time; no clock change in 2027.\n";
exit(0);

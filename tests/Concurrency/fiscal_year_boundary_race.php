<?php

/**
 * MySQL concurrency regression for fiscal numbering AROUND THE CALENDAR-YEAR BOUNDARY (F-02).
 *
 * Separate PHP processes (separate MySQL sessions) are released at the same instant. Each child freezes its
 * own clock at a chosen UTC instant, derives the fiscal sequence year exactly like the application does
 * (BusinessTime::currentYear of the organization's timezone) and allocates an official number from the real
 * generators inside a transaction that holds the lock briefly, like a document insert would.
 *
 *   php tests/Concurrency/fiscal_year_boundary_race.php [--sessions=4] [--database=commerce_erp_dev_test]
 *
 * Instants (Morocco was on UTC+1 in December 2025, so these are real UTC/local calendar splits):
 *   2025-12-31 23:30 UTC  =  2026-01-01 00:30 Africa/Casablanca  =  2025-12-31 18:30 America/New_York
 *   2026-01-01 00:30 UTC  =  2026-01-01 01:30 Africa/Casablanca
 *
 * Scenarios (every child of a scenario runs in parallel; ALL scenarios' children share one start barrier):
 *   cross-tz   N sessions each for a Casablanca org (year 2026), a New York org (year 2025) and an
 *              UTC org (year 2025), all at 2025-12-31 23:30 UTC → each org's own sequence 1..N of ITS year,
 *              no cross-organization collision.
 *   straddle   one UTC org, N sessions at 2025-12-31 23:30 UTC (year 2025) and N at 2026-01-01 00:30 UTC
 *              (year 2026), concurrently → two independent sequences 1..N, no year mix-up.
 * Generators raced: Invoice (N/YYYY), CreditNote (AV-NNNNN/YYYY), Quotation (DEV-N/YYYY), CustomerReturn
 * (RET-NNNNN/YYYY).
 *
 * Pass = every session succeeds (no deadlock / lock-wait timeout), numbers unique and consecutive per
 * (organization, year), the year inside every number is the expected business year, and InnoDB's deadlock
 * counter did not move. Safety: local *_test / *_dev MySQL databases only; each scenario creates its own
 * organizations, so existing data is never touched. Requires a migrated DB.
 */

use App\Models\Organization;
use App\Models\User;
use App\Services\CreditNoteNumberGenerator;
use App\Services\CustomerReturnNumberGenerator;
use App\Services\InvoiceNumberGenerator;
use App\Services\OrganizationCreator;
use App\Services\QuotationNumberGenerator;
use App\Support\BusinessTime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['sessions::', 'database::', 'child::', 'org::', 'start::', 'instant::']);
$database = $options['database'] ?? 'commerce_erp_dev_test';

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$host = (string) $connection->getConfig('host');
$actualDb = $connection->getDatabaseName();
if ($connection->getDriverName() !== 'mysql' || ! in_array($host, ['127.0.0.1', 'localhost'], true)
    || ! preg_match('/_(test|dev)$/', $actualDb)) {
    fwrite(STDERR, "Refusing to run against [{$connection->getDriverName()}://{$host}/{$actualDb}]: local *_test / *_dev MySQL databases only.\n");
    exit(2);
}

/** @var array<string, Closure(Organization, int): string> $generators */
$generators = [
    'Invoice' => fn (Organization $o, int $year) => app(InvoiceNumberGenerator::class)->next($o, $year),
    'CreditNote' => fn (Organization $o, int $year) => app(CreditNoteNumberGenerator::class)->next($o, $year),
    'Quotation' => fn (Organization $o, int $year) => app(QuotationNumberGenerator::class)->next($o, $year),
    'CustomerReturn' => fn (Organization $o, int $year) => app(CustomerReturnNumberGenerator::class)->next($o, $year),
];

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    $org = Organization::query()->findOrFail((int) $options['org']);
    $generate = $generators[$options['child']];
    // This process's clock is frozen at the scenario instant (an explicit UTC instant, never the system clock).
    Carbon::setTestNow(Carbon::parse((string) $options['instant'], 'UTC'));
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    try {
        $year = BusinessTime::currentYear($org);   // exactly what the application does for a year-keyed document
        $number = DB::transaction(function () use ($generate, $org, $year) {
            $number = $generate($org, $year);
            usleep(30000); // hold the lock like a document insert would

            return $number;
        });
        echo json_encode(['ok' => true, 'number' => $number, 'year' => $year]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage(), 200)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
$sessions = max(2, (int) ($options['sessions'] ?? 4));
$beforeBoundary = '2025-12-31 23:30:00';   // = 2026-01-01 00:30 in Casablanca (UTC+1 then)
$afterBoundary = '2026-01-01 00:30:00';

$newOrg = function (string $label, ?string $timezone): Organization {
    $owner = new User;
    $owner->name = "Year race {$label}";
    $owner->email = 'year-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Year race {$label}");
    if ($timezone !== null) {
        $org->settings = array_merge($org->settings ?? [], ['timezone' => $timezone]);
        $org->save();
    }

    return $org->fresh();
};

/** Launch every child of every group at ONE barrier; return results grouped per group key. */
$race = function (array $groups) use ($database): array {
    $start = microtime(true) + 4.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($groups as $key => [$generator, $org, $instant, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $errFile = tempnam(sys_get_temp_dir(), 'yr');
            $cmd = [PHP_BINARY, __FILE__, "--child={$generator}", "--org={$org->id}", "--start={$start}", "--instant={$instant}", "--database={$database}"];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
            if (! is_resource($proc)) {
                throw new RuntimeException('Could not start child process.');
            }
            $procs[] = [$key, $proc, $pipes, $errFile];
        }
    }
    $results = [];
    foreach ($procs as [$key, $proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[$key][] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)];
    }

    return $results;
};

$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
$sequenceOf = fn (string $number): int => (int) preg_replace('/\D/', '', preg_replace('/\/\d{4}$/', '', $number));
$yearOf = fn (string $number): int => (int) substr($number, -4);

/** Check one (group) result set: all ok, years as expected, numbers unique and 1..count. */
$verify = function (array $results, int $expectedYear, int $count) use ($sequenceOf, $yearOf): array {
    $errors = [];
    foreach ($results as $r) {
        if (! $r['ok']) {
            $errors[] = "{$r['class']}: {$r['message']}";
        }
    }
    $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
    $numbers = array_column($ok, 'number');
    if (count(array_unique($numbers)) !== count($numbers)) {
        $errors[] = 'DUPLICATE numbers: '.implode(', ', $numbers);
    }
    foreach ($ok as $r) {
        if ($r['year'] !== $expectedYear || $yearOf($r['number']) !== $expectedYear) {
            $errors[] = "wrong year: number {$r['number']} (child year {$r['year']}), expected {$expectedYear}";
        }
    }
    $seq = array_map($sequenceOf, $numbers);
    sort($seq);
    if ($errors === [] && $seq !== range(1, $count)) {
        $errors[] = 'non-consecutive: got ['.implode(',', $seq).'] expected [1..'.$count.']';
    }

    return [$errors, $numbers];
};

echo "Fiscal-year-boundary race on mysql://{$host}/{$actualDb} — {$sessions} sessions per group\n";
$before = $deadlocks();
$failures = 0;

foreach (array_keys($generators) as $name) {
    // --- scenario cross-tz: three organizations, three timezones, ONE instant -----------------------------
    $ma = $newOrg("{$name} ma", 'Africa/Casablanca');
    $ny = $newOrg("{$name} ny", 'America/New_York');
    $utc = $newOrg("{$name} utc", null);
    $results = $race([
        'ma' => [$name, $ma, $beforeBoundary, $sessions],
        'ny' => [$name, $ny, $beforeBoundary, $sessions],
        'utc' => [$name, $utc, $beforeBoundary, $sessions],
    ]);
    foreach (['ma' => 2026, 'ny' => 2025, 'utc' => 2025] as $key => $expectedYear) {
        [$errors, $numbers] = $verify($results[$key], $expectedYear, $sessions);
        printf("  %-15s cross-tz  %-4s year %d  %s  %s\n", $name, $key, $expectedYear, $errors === [] ? 'PASS' : 'FAIL', implode(' ', $numbers));
        foreach (array_unique($errors) as $error) {
            echo "      ✗ {$error}\n";
        }
        $failures += $errors === [] ? 0 : 1;
    }

    // --- scenario straddle: one UTC org, sessions on BOTH sides of the boundary, concurrently ----------------
    $same = $newOrg("{$name} straddle", null);
    $results = $race([
        'before' => [$name, $same, $beforeBoundary, $sessions],
        'after' => [$name, $same, $afterBoundary, $sessions],
    ]);
    foreach (['before' => 2025, 'after' => 2026] as $key => $expectedYear) {
        [$errors, $numbers] = $verify($results[$key], $expectedYear, $sessions);
        printf("  %-15s straddle  %-6s year %d  %s  %s\n", $name, $key, $expectedYear, $errors === [] ? 'PASS' : 'FAIL', implode(' ', $numbers));
        foreach (array_unique($errors) as $error) {
            echo "      ✗ {$error}\n";
        }
        $failures += $errors === [] ? 0 : 1;
    }
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

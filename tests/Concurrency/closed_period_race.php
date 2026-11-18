<?php

/**
 * MySQL concurrency regression for closed financial periods (Phase 1 / Step 4, F-03).
 *
 * Real invoice issuance (IssueInvoiceAction) races real month closing, in separate PHP processes
 * (separate MySQL sessions):
 *
 *   php tests/Concurrency/closed_period_race.php [--invoices=4] [--database=commerce_erp_dev_test]
 *
 * Scenario A — "close wins": a session takes the EXCLUSIVE lock on the month row (exactly what
 *   ManageFinanceMonthReviewAction::locked() does), holds it ~1.5 s, marks the month closed and commits.
 *   N issuers of September-dated invoices start while the lock is held. Every issuer must BLOCK on the
 *   guard's shared lock, then be refused ("est clôturée"); none may issue; each invoice stays a clean draft
 *   (no number, no issued_at, no audit row).
 *
 * Scenario B — "free race": N issuers and the REAL ManageFinanceMonthReviewAction::close() are released at
 *   ONE barrier. Whatever the interleaving, every issuer ends either issued or refused with the closed-period
 *   message (never a deadlock / lock-wait timeout / other error), the month ends closed, refused invoices are
 *   untouched drafts, issued ones carry consecutive numbers, and a sequential issue attempt made AFTER the
 *   close is refused for every remaining draft.
 *
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases
 * only; every scenario creates its own organization. Requires a migrated database.
 */

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderSource;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinancePeriod;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
$options = getopt('', ['invoices::', 'database::', 'child::', 'org::', 'user::', 'invoice::', 'start::', 'signal::']);
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

const NOW = '2026-11-15 10:00:00';   // UTC; Morocco is on UTC+0 in November 2026
const MONTH = '2026-09';             // ended, so the real close action accepts it
const INVOICE_DATE = '2026-09-20';

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
    $org = Organization::query()->findOrFail((int) $options['org']);
    $wait = function () use ($options) {
        while (microtime(true) < (float) $options['start']) {
            usleep(100);
        }
    };
    try {
        switch ($options['child']) {
            case 'issue':
                $user = User::query()->findOrFail((int) $options['user']);
                $invoice = Invoice::query()->withoutGlobalScopes()->findOrFail((int) $options['invoice']);
                // Scenario A: wait until the holder has the exclusive lock, THEN start (so we must block on it).
                if (isset($options['signal'])) {
                    while (! is_file($options['signal'])) {
                        usleep(2000);
                    }
                } else {
                    $wait();
                }
                $t = microtime(true);
                app(IssueInvoiceAction::class)->execute($user, $invoice);
                echo json_encode(['ok' => true, 'ms' => (int) ((microtime(true) - $t) * 1000)]);
                break;
            case 'close':
                $user = User::query()->findOrFail((int) $options['user']);
                $wait();
                app(ManageFinanceMonthReviewAction::class)->close($user, $org, FinancePeriod::fromMonth(MONTH), 'race', true);
                echo json_encode(['ok' => true]);
                break;
            case 'hold':
                DB::transaction(function () use ($org, $options) {
                    $row = DB::table('finance_month_reviews')->where('organization_id', $org->id)->whereDate('period', MONTH.'-01')->lockForUpdate()->first();
                    if (! $row) {
                        DB::table('finance_month_reviews')->insert(['organization_id' => $org->id, 'period' => MONTH.'-01', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                        DB::table('finance_month_reviews')->where('organization_id', $org->id)->whereDate('period', MONTH.'-01')->lockForUpdate()->first();
                    }
                    file_put_contents($options['signal'], 'locked');
                    usleep(1500000);
                    DB::table('finance_month_reviews')->where('organization_id', $org->id)->whereDate('period', MONTH.'-01')
                        ->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);
                });
                echo json_encode(['ok' => true]);
                break;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
$count = max(2, (int) ($options['invoices'] ?? 4));

$world = function (string $label) use ($count): array {
    $owner = new User;
    $owner->name = "Period race {$label}";
    $owner->email = 'period-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Period race {$label}");
    $org->settings = array_merge($org->settings ?? [], ['timezone' => 'Africa/Casablanca']);
    $org->save();
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    $account = new FinancialAccount;
    $account->forceFill(['organization_id' => $org->id, 'name' => 'Caisse', 'code' => 'CASH', 'type' => 'cash', 'status' => 'active',
        'currency_code' => 'MAD', 'accepted_methods' => ['cash']])->save();

    $invoiceIds = [];
    for ($i = 0; $i < $count; $i++) {
        $order = app(CreateSalesOrderAction::class)->execute($owner, $org, $store,
            ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null], SalesOrderSource::Pos);
        app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), ['line_type' => 'custom', 'description' => 'Prestation', 'reference' => null,
            'unit_label' => 'u', 'quantity' => '1.0000', 'unit_price_excl_tax' => '100.0000', 'tax_rate_id' => null, 'discount_type' => 'none', 'discount_value' => '0.0000']);
        $order = app(ConfirmSalesOrderAction::class)->execute($owner, $order->fresh())->fresh();
        app(RecordPaymentAction::class)->execute($owner, $order, ['method' => 'cash', 'financial_account_id' => $account->id, 'amount' => (string) $order->total_incl_tax,
            'payment_date' => now()->toDateString(), 'reference' => null, 'external_reference' => null, 'notes' => null], (string) Str::uuid(), 1);
        $invoiceIds[] = app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order->fresh(), ['invoice_date' => INVOICE_DATE])->id;
    }

    return [$owner, $org, $invoiceIds];
};

$launch = function (array $jobs, float $start) use ($database): array {
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as $job) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$job['child'], '--org='.$job['org'], '--user='.$job['user'], '--start='.$start, '--database='.$database];
        foreach (['invoice', 'signal'] as $extra) {
            if (isset($job[$extra])) {
                $cmd[] = "--{$extra}={$job[$extra]}";
            }
        }
        $errFile = tempnam(sys_get_temp_dir(), 'cp');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start child process.');
        }
        $procs[] = [$job['child'], $proc, $pipes, $errFile];
    }

    return $procs;
};
$collect = function (array $procs): array {
    $results = [];
    foreach ($procs as [$kind, $proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[$kind][] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)];
    }

    return $results;
};

$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
$isClosedRefusal = fn (array $r): bool => ! $r['ok'] && $r['class'] === 'ValidationException' && str_contains($r['message'], 'clôturée');
$monthStatus = fn (Organization $org) => DB::table('finance_month_reviews')->where('organization_id', $org->id)->whereDate('period', MONTH.'-01')->value('status');
$draftIsClean = function (int $id): bool {
    $row = DB::table('invoices')->where('id', $id)->first();

    return $row->status === 'draft' && $row->invoice_number === null && $row->issued_at === null
        && ! DB::table('audit_logs')->where('auditable_type', Invoice::class)->where('auditable_id', $id)->where('event', 'invoice.issued')->exists();
};

echo "Closed-period race on mysql://{$host}/{$actualDb} — {$count} invoices per scenario\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-34s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

// --- Scenario A: close wins (deterministic) ----------------------------------------------------------------
[$owner, $org, $ids] = $world('A');
$signal = tempnam(sys_get_temp_dir(), 'cpsig');
@unlink($signal);
$jobs = [['child' => 'hold', 'org' => $org->id, 'user' => $owner->id, 'signal' => $signal]];
foreach ($ids as $id) {
    $jobs[] = ['child' => 'issue', 'org' => $org->id, 'user' => $owner->id, 'invoice' => $id, 'signal' => $signal];
}
$results = $collect($launch($jobs, microtime(true)));
$errors = [];
foreach ($results['hold'] as $r) {
    $r['ok'] || $errors[] = "holder failed: {$r['class']} {$r['message']}";
}
foreach ($results['issue'] as $r) {
    $isClosedRefusal($r) || $errors[] = 'issuer was not refused with the closed-period message: '.json_encode($r);
}
foreach ($ids as $id) {
    $draftIsClean($id) || $errors[] = "invoice {$id} is not a clean draft after the refusal";
}
$monthStatus($org) === 'closed' || $errors[] = 'month is not closed';
@unlink($signal);
$report("A close wins: {$count} issuers refused, drafts untouched", $errors);

// --- Scenario B: free race with the real close action ------------------------------------------------------
[$owner, $org, $ids] = $world('B');
$jobs = [['child' => 'close', 'org' => $org->id, 'user' => $owner->id]];
foreach ($ids as $id) {
    $jobs[] = ['child' => 'issue', 'org' => $org->id, 'user' => $owner->id, 'invoice' => $id];
}
$results = $collect($launch($jobs, microtime(true) + 4.0));
$errors = [];
foreach ($results['close'] as $r) {
    $r['ok'] || $errors[] = "close failed: {$r['class']} {$r['message']}";
}
$issued = [];
foreach ($results['issue'] as $index => $r) {
    if (! $r['ok'] && ! $isClosedRefusal($r)) {
        $errors[] = 'unexpected issuer outcome (deadlock / timeout / other): '.json_encode($r);
    }
}
$monthStatus($org) === 'closed' || $errors[] = 'month is not closed after the race';
$numbers = [];
foreach ($ids as $id) {
    $row = DB::table('invoices')->where('id', $id)->first();
    if ($row->status === 'issued') {
        $numbers[] = (int) $row->invoice_number;
    } elseif (! $draftIsClean($id)) {
        $errors[] = "invoice {$id} is neither cleanly issued nor a clean draft (status {$row->status})";
    }
}
sort($numbers);
($numbers === [] || $numbers === range(1, count($numbers))) || $errors[] = 'issued numbers are not consecutive: ['.implode(',', $numbers).']';
// After the close committed, NO remaining draft may be issued.
foreach ($ids as $id) {
    if (DB::table('invoices')->where('id', $id)->value('status') !== 'draft') {
        continue;
    }
    try {
        app(IssueInvoiceAction::class)->execute($owner->fresh(), Invoice::query()->withoutGlobalScopes()->findOrFail($id));
        $errors[] = "invoice {$id} was issued AFTER the month closed";
    } catch (ValidationException $e) {
        str_contains(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'clôturée') || $errors[] = 'late issue refused for another reason: '.json_encode($e->errors());
    }
}
$report('B free race: outcomes consistent', $errors);
printf("      (issued before close: %d, refused: %d)\n", count($numbers), $count - count($numbers));

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

<?php

/**
 * MySQL concurrency regression: outgoing payments must never over-allocate one obligation.
 *
 * N REAL RecordFinanceOutgoingPaymentAction calls (different idempotency keys) race for the SAME obligation in
 * separate PHP processes (separate MySQL sessions), released at ONE barrier:
 *
 *   php tests/Concurrency/finance_outgoing_overpayment_race.php [--payers=4] [--rounds=6] [--database=commerce_erp_dev_test]
 *
 * Scenarios (each round uses a fresh organization and obligation of 120.0000):
 *   a) every payer pays the FULL remaining (120) from the SAME account   -> exactly 1 may succeed;
 *   b) every payer pays the FULL remaining (120) from DIFFERENT accounts -> exactly 1 may succeed;
 *   c) every payer pays 50 from different accounts                       -> exactly 2 may succeed (floor(120/50)).
 * Invariants: sum of POSTED allocations on the obligation <= planned_amount; the number of successes equals the
 * expected one; every refusal is the clean "dépasse le reste" business message (never a deadlock / timeout / other
 * error); InnoDB's deadlock counter did not move.
 *
 * Why it matters (snapshot trap): FinanceObligationCalculator::paid(lock: true) filters allocations through an EXISTS
 * subquery on finance_outgoing_payments. Under InnoDB REPEATABLE READ the outer SELECT ... FOR UPDATE reads the latest
 * committed allocations, but the subquery table is read from the transaction snapshot (fixed by the earlier plain
 * idempotency lookup). A concurrent payment committed after that snapshot shows its allocation but not its "posted"
 * payment row, so it is not counted as paid and a second full payment passes the remaining check.
 *
 * Safety: local *_test / *_dev MySQL databases only. Requires a migrated database.
 */

use App\Actions\Finance\ManageFinanceObligationAction;
use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['payers::', 'rounds::', 'database::', 'child::', 'org::', 'user::', 'obligation::', 'account::', 'amount::', 'start::', 'lag::']);
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

const NOW = '2026-11-15 10:00:00';
const PLANNED = '120.0000';

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
    $org = Organization::query()->findOrFail((int) $options['org']);
    $user = User::query()->findOrFail((int) $options['user']);
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    usleep((int) ($options['lag'] ?? 0));
    try {
        $payment = app(RecordFinanceOutgoingPaymentAction::class)->execute($user, $org, [
            'store_id' => null, 'financial_account_id' => (int) $options['account'], 'supplier_id' => null, 'beneficiary_name' => 'Fournisseur',
            'payment_method' => 'bank_transfer', 'amount' => $options['amount'], 'currency_code' => 'MAD', 'payment_date' => '2026-11-15',
            'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['obligation_id' => (int) $options['obligation'], 'amount' => $options['amount']]],
        ]);
        echo json_encode(['ok' => true, 'payment' => $payment->id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
$payers = max(2, (int) ($options['payers'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 6));

$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
echo "Outgoing payment over-allocation race on mysql://{$host}/{$actualDb} — {$payers} payers x {$rounds} rounds x 3 scenarios\n";
$before = $deadlocks();
$failures = 0;

$scenarios = [
    'a same account, full remaining' => ['accounts' => 1, 'amount' => '120.0000', 'expected' => 1],
    'b different accounts, full remaining' => ['accounts' => $payers, 'amount' => '120.0000', 'expected' => 1],
    'c different accounts, 50 each' => ['accounts' => $payers, 'amount' => '50.0000', 'expected' => 2],
];

for ($round = 1; $round <= $rounds; $round++) {
    foreach ($scenarios as $label => $scenario) {
        $owner = new User;
        $owner->name = "Overpay race {$round}";
        $owner->email = 'overpay-'.Str::lower(Str::random(12)).'@race.local';
        $owner->password = 'race-not-a-login';
        $owner->email_verified_at = now();
        $owner->save();
        $org = app(OrganizationCreator::class)->create($owner, "Overpay race {$round}");
        $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
        $owner->active_organization_id = $org->id;
        $owner->active_store_id = $store->id;
        $owner->save();
        $accountIds = [];
        for ($i = 0; $i < $scenario['accounts']; $i++) {
            $account = new FinancialAccount;
            $account->forceFill(['organization_id' => $org->id, 'name' => "Banque {$i}", 'code' => "BANK{$i}", 'type' => 'bank', 'status' => 'active',
                'currency_code' => 'MAD', 'accepted_methods' => ['bank_transfer']])->save();
            $accountIds[] = $account->id;
        }
        $obligation = app(ManageFinanceObligationAction::class)->create($owner, $org, [
            'store_id' => $store->id, 'category' => 'supplier', 'label' => 'Facture', 'supplier_id' => null, 'beneficiary_name' => 'Fournisseur',
            'reference' => null, 'due_date' => '2026-12-10', 'planned_amount' => PLANNED, 'currency_code' => 'MAD',
            'planned_payment_method' => null, 'preferred_financial_account_id' => null, 'notes' => null,
        ]);

        $start = microtime(true) + 4.0;
        $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
        $procs = [];
        for ($i = 0; $i < $payers; $i++) {
            $cmd = [PHP_BINARY, __FILE__, '--child=pay', '--org='.$org->id, '--user='.$owner->id, '--start='.$start, '--database='.$database,
                '--obligation='.$obligation->id, '--account='.$accountIds[$i % count($accountIds)], '--amount='.$scenario['amount'], '--lag='.(($i % 3) * 7000)];
            $errFile = tempnam(sys_get_temp_dir(), 'op');
            $procs[] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env), $pipes, $errFile];
        }
        $success = 0;
        $errors = [];
        foreach ($procs as [$proc, $pipes, $errFile]) {
            $out = stream_get_contents($pipes[1]);
            proc_close($proc);
            $err = (string) @file_get_contents($errFile);
            @unlink($errFile);
            $r = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)];
            if ($r['ok']) {
                $success++;
            } elseif (! (($r['class'] ?? '') === 'ValidationException' && str_contains($r['message'], 'dépasse'))) {
                $errors[] = 'unexpected payer outcome (deadlock / timeout / other): '.json_encode($r);
            }
        }

        $postedSum = (string) (DB::table('finance_outgoing_payment_allocations as a')
            ->join('finance_outgoing_payments as p', 'p.id', '=', 'a.outgoing_payment_id')
            ->where('a.obligation_id', $obligation->id)->where('p.status', 'posted')->sum('a.amount') ?: '0');
        if (bccomp($postedSum, PLANNED, 4) > 0) {
            $errors[] = "OVER-ALLOCATION: posted allocations {$postedSum} exceed planned ".PLANNED;
        }
        if ($success !== $scenario['expected']) {
            $errors[] = "expected {$scenario['expected']} successful payment(s), got {$success}";
        }
        printf("  round %2d  %-38s successes %d (expected %d), posted %s   %s\n", $round, $label, $success, $scenario['expected'], $postedSum, $errors === [] ? 'PASS' : 'FAIL');
        foreach ($errors as $error) {
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

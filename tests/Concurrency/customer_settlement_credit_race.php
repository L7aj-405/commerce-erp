<?php

/**
 * MySQL concurrency regression for Finance clients Phase 2.
 *
 *   php tests/Concurrency/customer_settlement_credit_race.php [--sessions=4] [--rounds=3] [--database=commerce_erp_dev_test]
 *
 *  a) PAYMENT ALLOCATION: N REAL RecordCustomerSettlementAction calls (different operation ids) race to pay the FULL remaining
 *     balance of the SAME order from separate PHP processes released at ONE barrier -> exactly 1 may succeed, posted allocations
 *     never exceed the order total, every refusal is the clean "dépasse le reste" business message.
 *  b) SAME OPERATION ID: N identical requests (one operation id) -> exactly one Payment row exists whatever the outcome.
 *  c) CREDIT LIMIT: N REAL ConfirmSalesOrderAction calls for N DIFFERENT draft orders of one customer (each 700 against a limit of
 *     1000, enforcement `block`) -> exactly 1 order may be confirmed (the per-customer lock serializes the credit check).
 *
 * Safety: local *_test / *_dev MySQL databases only. Requires a migrated database.
 */

use App\Actions\Finance\Customers\RecordCustomerSettlementAction;
use App\Actions\Finance\Customers\SaveCustomerFinancialTermsAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['sessions::', 'rounds::', 'database::', 'child::', 'user::', 'customer::', 'order::', 'account::', 'op::', 'start::', 'lag::']);
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
if ($connection->getDriverName() !== 'mysql' || ! in_array($host, ['127.0.0.1', 'localhost'], true) || ! preg_match('/_(test|dev)$/', $actualDb)) {
    fwrite(STDERR, "Refusing to run against [{$connection->getDriverName()}://{$host}/{$actualDb}]: local *_test / *_dev MySQL databases only.\n");
    exit(2);
}

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    $user = User::query()->findOrFail((int) $options['user']);
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    usleep((int) ($options['lag'] ?? 0));
    try {
        if ($options['child'] === 'pay') {
            $customer = Customer::query()->findOrFail((int) $options['customer']);
            $payment = app(RecordCustomerSettlementAction::class)->execute($user, $customer, [
                'financial_account_id' => (int) $options['account'], 'method' => 'bank_transfer', 'amount' => '1000.0000', 'payment_date' => now()->toDateString(),
                'allocations' => [['sales_order_id' => (int) $options['order'], 'amount' => '1000.0000']],
            ], (string) $options['op']);
            echo json_encode(['ok' => true, 'payment' => $payment->id]);
        } else {
            $order = SalesOrder::query()->findOrFail((int) $options['order']);
            app(ConfirmSalesOrderAction::class)->execute($user, $order);
            echo json_encode(['ok' => true]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
$sessions = max(2, (int) ($options['sessions'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 3));
$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
echo "Customer settlement + credit race on mysql://{$host}/{$actualDb} — {$sessions} sessions x {$rounds} rounds\n";
$before = $deadlocks();
$failures = 0;

$fixture = function (string $label) {
    $owner = new User;
    $owner->name = "Race {$label}";
    $owner->email = 'cust-race-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Customer race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    $account = new FinancialAccount;
    $account->forceFill(['organization_id' => $org->id, 'name' => 'Banque', 'code' => 'BANK', 'type' => 'bank', 'status' => 'active', 'currency_code' => 'MAD', 'accepted_methods' => ['bank_transfer']])->save();
    $customer = new Customer;
    $customer->forceFill(['organization_id' => $org->id, 'type' => 'individual', 'display_name' => 'Client course', 'status' => 'active'])->save();

    return [$owner, $org, $store, $account, $customer];
};
$newOrder = function (User $owner, $org, $store, Customer $customer, string $price, bool $confirm): SalesOrder {
    $order = app(CreateSalesOrderAction::class)->execute($owner, $org, $store, ['customer_id' => $customer->id, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => 'race']);
    app(SaveSalesOrderLineAction::class)->execute($owner, $order, ['line_type' => 'custom', 'description' => 'Race', 'reference' => null, 'unit_label' => 'u', 'quantity' => '1.0000',
        'unit_price_excl_tax' => $price, 'tax_rate_id' => null, 'discount_type' => 'none', 'discount_value' => '0.0000']);

    return $confirm ? app(ConfirmSalesOrderAction::class)->execute($owner, $order)->fresh() : $order->fresh();
};
$race = function (string $mode, User $owner, Closure $args, int $count) use ($database): array {
    $start = microtime(true) + 4.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$mode, '--user='.$owner->id, '--start='.$start, '--database='.$database, '--lag='.(($i % 3) * 7000)];
        foreach ($args($i) as $key => $value) {
            $cmd[] = "--{$key}={$value}";
        }
        $errFile = tempnam(sys_get_temp_dir(), 'cr');
        $procs[] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env), $pipes, $errFile];
    }
    $results = [];
    foreach ($procs as [$proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)];
    }

    return $results;
};
$report = function (string $label, array $errors, string $detail) use (&$failures): void {
    printf("  %-44s %s   %s\n", $label, $detail, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

for ($round = 1; $round <= $rounds; $round++) {
    // a) different operation ids, same order, full remaining.
    [$owner, $org, $store, $account, $customer] = $fixture("a{$round}");
    $order = $newOrder($owner, $org, $store, $customer, '1000.0000', true);
    $results = $race('pay', $owner, fn ($i) => ['customer' => $customer->id, 'order' => $order->id, 'account' => $account->id, 'op' => (string) Str::uuid()], $sessions);
    $errors = [];
    $ok = count(array_filter($results, fn ($r) => $r['ok']));
    foreach ($results as $r) {
        if (! $r['ok'] && ! (($r['class'] ?? '') === 'ValidationException' && str_contains($r['message'], 'dépasse'))) {
            $errors[] = 'unexpected outcome: '.json_encode($r);
        }
    }
    $allocated = (string) (DB::table('payment_allocations')->where('sales_order_id', $order->id)->sum('amount') ?: '0');
    if ($ok !== 1) {
        $errors[] = "expected exactly 1 settlement, got {$ok}";
    }
    if (bccomp($allocated, '1000.0000', 4) > 0) {
        $errors[] = "OVER-ALLOCATION: {$allocated} allocated on a 1000 order";
    }
    $report("round {$round} a) concurrent allocation to one order", $errors, "successes {$ok} (expected 1), allocated {$allocated}");

    // b) same operation id.
    [$owner, $org, $store, $account, $customer] = $fixture("b{$round}");
    $order = $newOrder($owner, $org, $store, $customer, '1000.0000', true);
    $op = (string) Str::uuid();
    $results = $race('pay', $owner, fn ($i) => ['customer' => $customer->id, 'order' => $order->id, 'account' => $account->id, 'op' => $op], $sessions);
    $errors = [];
    $payments = DB::table('payments')->where('organization_id', $org->id)->count();
    foreach ($results as $r) {
        if (! $r['ok'] && ! str_contains($r['message'] ?? '', 'dépasse')) {
            $errors[] = 'unexpected outcome: '.json_encode($r);
        }
    }
    if ($payments !== 1) {
        $errors[] = "expected exactly 1 payment for one operation id, found {$payments}";
    }
    $report("round {$round} b) duplicate operation id", $errors, "payments {$payments} (expected 1)");

    // c) credit limit.
    [$owner, $org, $store, $account, $customer] = $fixture("c{$round}");
    app(SaveCustomerFinancialTermsAction::class)->execute($owner, $customer, ['credit_enabled' => true, 'credit_limit' => '1000.0000', 'policy' => 'block', 'financial_status' => 'normal']);
    $org->settings = array_replace($org->settings ?? [], ['customer_credit' => ['enforcement' => 'block']]);
    $org->save();
    $orders = [];
    for ($i = 0; $i < $sessions; $i++) {
        $orders[] = $newOrder($owner, $org, $store, $customer, '700.0000', false);
    }
    $results = $race('confirm', $owner, fn ($i) => ['order' => $orders[$i]->id], $sessions);
    $errors = [];
    $confirmed = SalesOrder::query()->where('organization_id', $org->id)->where('status', 'confirmed')->count();
    foreach ($results as $r) {
        if (! $r['ok'] && ! (($r['class'] ?? '') === 'ValidationException' && str_contains($r['message'], 'plafond'))) {
            $errors[] = 'unexpected outcome (deadlock / timeout / other): '.json_encode($r);
        }
    }
    if ($confirmed !== 1) {
        $errors[] = "expected exactly 1 confirmed order under a 1000 limit, found {$confirmed}";
    }
    $report("round {$round} c) concurrent credit-limit consumption", $errors, "confirmed {$confirmed} (expected 1)");
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

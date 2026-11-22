<?php

/**
 * MySQL concurrency regression: voiding a supplier invoice against payments on its obligation (Phase 2 Sprint 1 / PR9).
 *
 * The REAL VoidSupplierInvoiceAction races N REAL RecordFinanceOutgoingPaymentAction calls on the SAME obligation,
 * in separate PHP processes (separate MySQL sessions), released at ONE barrier:
 *
 *   php tests/Concurrency/supplier_invoice_void_vs_payment_race.php [--payers=4] [--rounds=3] [--database=commerce_erp_dev_test]
 *
 * Invariants per round, whatever the interleaving:
 *   - every outcome is clean: void and payers either succeed or are refused with the expected business message
 *     (never a deadlock / lock-wait timeout / other error);
 *   - NEVER a posted payment allocation on a cancelled obligation;
 *   - void succeeded  <=> obligation cancelled and invoice void AND no posted allocation exists;
 *     void refused    <=> invoice still approved, obligation open, and at least one posted payment exists;
 *   - paid never exceeds planned.
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases only;
 * every round creates its own organization. Requires a migrated database.
 */

use App\Actions\Finance\RecordFinanceOutgoingPaymentAction;
use App\Actions\SupplierInvoices\ApproveSupplierInvoiceAction;
use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Actions\SupplierInvoices\VoidSupplierInvoiceAction;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['payers::', 'rounds::', 'database::', 'child::', 'org::', 'user::', 'invoice::', 'obligation::', 'account::', 'start::', 'lag::']);
$database = $options['database'] ?? 'commerce_erp_dev_test';

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
    'SUPPLIER_INVOICES_ENABLED' => 'true',
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

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
    $org = Organization::query()->findOrFail((int) $options['org']);
    $user = User::query()->findOrFail((int) $options['user']);
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    // The void session may start a little after the payers (rounds stagger it) so both orders are exercised.
    if ($options['child'] === 'void') {
        usleep((int) ($options['lag'] ?? 0));
    }
    try {
        if ($options['child'] === 'void') {
            $invoice = SupplierInvoice::query()->withoutGlobalScopes()->findOrFail((int) $options['invoice']);
            $result = app(VoidSupplierInvoiceAction::class)->execute($user, $invoice, ['reason_code' => 'wrong_data_entry', 'reason' => 'race']);
            echo json_encode(['ok' => true, 'voided_now' => $result['voided_now']]);
        } else {
            $payment = app(RecordFinanceOutgoingPaymentAction::class)->execute($user, $org, [
                'store_id' => null, 'financial_account_id' => (int) $options['account'], 'supplier_id' => null, 'beneficiary_name' => 'Fournisseur',
                'payment_method' => 'bank_transfer', 'amount' => '10.0000', 'currency_code' => 'MAD', 'payment_date' => '2026-11-15',
                'reference' => null, 'notes' => null, 'idempotency_key' => (string) Str::uuid(),
                'allocations' => [['obligation_id' => (int) $options['obligation'], 'amount' => '10.0000']],
            ]);
            echo json_encode(['ok' => true, 'payment' => $payment->id]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
$payers = max(1, (int) ($options['payers'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 3));

$world = function (string $label): array {
    $owner = new User;
    $owner->name = "Void race {$label}";
    $owner->email = 'void-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Void race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    $supplierId = DB::table('suppliers')->insertGetId(['organization_id' => $org->id, 'name' => 'Fournisseur course', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $tax = new TaxRate;
    $tax->organization_id = $org->id;
    $tax->name = 'TVA 20';
    $tax->rate = '20.0000';
    $tax->status = 'active';
    $tax->save();
    $productId = DB::table('products')->insertGetId(['organization_id' => $org->id, 'name' => 'Article', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $productId;
    $variant->sku = 'RACE-'.Str::upper(Str::random(8));
    $variant->default_sale_price = '10.0000';
    $variant->unit_price_ht = '10.0000';
    $variant->status = 'active';
    $variant->save();
    $account = new FinancialAccount;
    $account->forceFill(['organization_id' => $org->id, 'name' => 'Banque', 'code' => 'BANK', 'type' => 'bank', 'status' => 'active',
        'currency_code' => 'MAD', 'accepted_methods' => ['bank_transfer']])->save();

    $invoice = app(SaveSupplierInvoiceDraftAction::class)->execute($owner, $org, [
        'store_id' => $store->id, 'supplier_id' => $supplierId, 'invoice_number' => 'VOID-'.Str::upper(Str::random(6)), 'invoice_date' => '2026-11-10', 'currency_code' => 'MAD',
        'control_total_ttc' => '120.00',
        'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $variant->id, 'description' => 'Article', 'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $tax->id]],
        'installments' => [['due_date' => '2026-12-10', 'amount' => '120.00']],
    ])['invoice'];
    app(ApproveSupplierInvoiceAction::class)->execute($owner, $invoice, ['control_total_ttc' => '120.00', 'acknowledge_unmatched' => true, 'match_note' => 'race']);
    $obligationId = (int) DB::table('finance_obligations')->where('organization_id', $org->id)->value('id');

    return [$owner, $org, $invoice, $obligationId, $account];
};

$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
echo "Supplier invoice void vs payment race on mysql://{$host}/{$actualDb} — {$payers} payers x {$rounds} rounds\n";
$before = $deadlocks();
$failures = 0;

for ($round = 1; $round <= $rounds; $round++) {
    [$owner, $org, $invoice, $obligationId, $account] = $world("R{$round}");
    $start = microtime(true) + 4.0;
    $jobs = [['void', ['invoice' => $invoice->id, 'lag' => ($round - 1) * 60000]]];
    for ($i = 0; $i < $payers; $i++) {
        $jobs[] = ['pay', ['obligation' => $obligationId, 'account' => $account->id]];
    }
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as [$kind, $extra]) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$kind, '--org='.$org->id, '--user='.$owner->id, '--start='.$start, '--database='.$database];
        foreach ($extra as $k => $v) {
            $cmd[] = "--{$k}={$v}";
        }
        $errFile = tempnam(sys_get_temp_dir(), 'vp');
        $procs[] = [$kind, proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env), $pipes, $errFile];
    }
    $results = [];
    foreach ($procs as [$kind, $proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[$kind][] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)];
    }

    $errors = [];
    $void = $results['void'][0];
    $voided = $void['ok'];
    if (! $voided && ! (($void['class'] ?? '') === 'ValidationException' && str_contains($void['message'], 'paiement'))) {
        $errors[] = 'unexpected void outcome: '.json_encode($void);
    }
    $paid = 0;
    foreach ($results['pay'] as $r) {
        if ($r['ok']) {
            $paid++;
        } elseif (! (($r['class'] ?? '') === 'ValidationException' && str_contains($r['message'], 'annulée'))) {
            $errors[] = 'unexpected payer outcome (deadlock / timeout / other): '.json_encode($r);
        }
    }
    $obligation = DB::table('finance_obligations')->where('id', $obligationId)->first();
    $postedAllocations = DB::table('finance_outgoing_payment_allocations as a')
        ->join('finance_outgoing_payments as p', fn ($j) => $j->on('p.id', '=', 'a.outgoing_payment_id'))
        ->where('a.obligation_id', $obligationId)->where('p.status', 'posted');
    $postedCount = (clone $postedAllocations)->count();
    $postedSum = (string) ((clone $postedAllocations)->sum('a.amount') ?: '0');
    $status = DB::table('supplier_invoices')->where('id', $invoice->id)->value('status');

    if ($obligation->cancelled_at !== null && $postedCount > 0) {
        $errors[] = "a posted payment exists on a CANCELLED obligation ({$postedCount})";
    }
    if ($voided && ($obligation->cancelled_at === null || $status !== 'void' || $postedCount !== 0)) {
        $errors[] = "void reported success but state is inconsistent (invoice {$status}, cancelled_at ".json_encode($obligation->cancelled_at).", posted {$postedCount})";
    }
    if (! $voided && ($status !== 'approved' || $obligation->cancelled_at !== null || $postedCount < 1)) {
        $errors[] = "void was refused but state is inconsistent (invoice {$status}, posted {$postedCount})";
    }
    if (bccomp($postedSum, '120', 4) > 0) {
        $errors[] = "paid {$postedSum} exceeds planned 120";
    }
    if ($postedCount !== $paid) {
        $errors[] = "payers reporting success ({$paid}) differ from posted allocations ({$postedCount})";
    }
    printf("  round %d: void %-8s payments posted %d/%d   %s\n", $round, $voided ? 'WON' : 'REFUSED', $postedCount, $payers, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

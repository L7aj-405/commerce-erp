<?php

/**
 * MySQL concurrency regression for supplier invoice approval (Phase 2 Sprint 1 / PR8).
 *
 * Real ApproveSupplierInvoiceAction runs in separate PHP processes (separate MySQL sessions):
 *
 *   php tests/Concurrency/supplier_invoice_approval_race.php [--sessions=6] [--database=commerce_erp_dev_test]
 *
 * Phase 1 — "same draft": N sessions approve the SAME draft (two installments across a year boundary) at ONE
 *   barrier. Pass = exactly one session approves (approved_now), the others return the already-approved invoice,
 *   no errors / deadlock / lock timeout, exactly one obligation per installment (numbers OBL-2026-000001 and
 *   OBL-2027-000001), exactly one supplier_invoice.approved audit row.
 *
 * Phase 2 — "approve vs month close":
 *   A (close wins, deterministic): a session holds the EXCLUSIVE lock on the month row (what
 *     ManageFinanceMonthReviewAction::locked() does) while N approvers of N distinct drafts dated in that month
 *     start; every approver must block, then be refused ("est clôturée"); every draft stays a clean draft with no
 *     obligation, no audit row and no consumed obligation number.
 *   B (free race): N approvers and the REAL ManageFinanceMonthReviewAction::close() are released at one barrier;
 *     each approver ends approved (with exactly its obligation) or refused with the closed-period message, never a
 *     deadlock / timeout / other error; the month ends closed; refused drafts are untouched; obligation numbers of
 *     the approved ones are consecutive; every draft still open after the close is refused when approved afterwards.
 *
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases only;
 * every scenario creates its own organization. Requires a migrated database.
 */

use App\Actions\Finance\ManageFinanceMonthReviewAction;
use App\Actions\SupplierInvoices\ApproveSupplierInvoiceAction;
use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Models\Organization;
use App\Models\ProductVariant;
use App\Models\SupplierInvoice;
use App\Models\TaxRate;
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
$options = getopt('', ['sessions::', 'database::', 'child::', 'org::', 'user::', 'invoice::', 'start::', 'signal::']);
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

const NOW = '2026-11-15 10:00:00';   // UTC; Morocco is on UTC+0 in November 2026
const MONTH = '2026-09';             // ended, so the real close action accepts it

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
            case 'approve':
                $user = User::query()->findOrFail((int) $options['user']);
                $invoice = SupplierInvoice::query()->withoutGlobalScopes()->findOrFail((int) $options['invoice']);
                if (isset($options['signal'])) {
                    while (! is_file($options['signal'])) {
                        usleep(2000);
                    }
                } else {
                    $wait();
                }
                $t = microtime(true);
                $result = app(ApproveSupplierInvoiceAction::class)->execute($user, $invoice, [
                    'control_total_ttc' => (string) $invoice->total_ttc, 'acknowledge_unmatched' => true, 'match_note' => 'race',
                ]);
                echo json_encode(['ok' => true, 'approved_now' => $result['approved_now'], 'ms' => (int) ((microtime(true) - $t) * 1000)]);
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
$sessions = max(2, (int) ($options['sessions'] ?? 6));

/** One organization with an owner, a store, a supplier, a tax rate and a variant. @return array{User, Organization, array<string, mixed>} */
$world = function (string $label): array {
    $owner = new User;
    $owner->name = "Invoice race {$label}";
    $owner->email = 'invoice-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Invoice race {$label}");
    $org->settings = array_merge($org->settings ?? [], ['timezone' => 'Africa/Casablanca']);
    $org->save();
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

    return [$owner, $org, ['store_id' => $store->id, 'supplier_id' => $supplierId, 'tax_rate_id' => $tax->id, 'variant_id' => $variant->id]];
};

/** @param array<string, mixed> $ctx @param list<array{due_date: string, amount: string}> $installments */
$draft = function (User $owner, Organization $org, array $ctx, string $number, string $date, string $unitPrice, array $installments): SupplierInvoice {
    // Distinct totals per invoice avoid the (non-blocking) same-total soft warning, which would need acknowledging.
    $totalTtc = bcmul(bcadd($unitPrice, '0', 2), '1.2', 2);

    return app(SaveSupplierInvoiceDraftAction::class)->execute($owner, $org, [
        'store_id' => $ctx['store_id'], 'supplier_id' => $ctx['supplier_id'], 'invoice_number' => $number, 'invoice_date' => $date, 'currency_code' => 'MAD',
        'control_total_ttc' => $totalTtc,
        'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $ctx['variant_id'], 'description' => 'Article', 'quantity' => '1', 'unit_price_ht' => $unitPrice, 'tax_rate_id' => $ctx['tax_rate_id']]],
        'installments' => $installments,
    ])['invoice'];
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
        $errFile = tempnam(sys_get_temp_dir(), 'sir');
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
$obligationCount = fn (int $invoiceId): int => DB::table('finance_obligations as o')
    ->join('supplier_invoice_installments as i', fn ($j) => $j->on('i.id', '=', 'o.supplier_invoice_installment_id')->on('i.organization_id', '=', 'o.organization_id'))
    ->where('i.supplier_invoice_id', $invoiceId)->count();
$approvedAudits = fn (int $invoiceId): int => DB::table('audit_logs')->where('auditable_type', SupplierInvoice::class)->where('auditable_id', $invoiceId)->where('event', 'supplier_invoice.approved')->count();
$cleanDraft = fn (int $invoiceId): bool => DB::table('supplier_invoices')->where('id', $invoiceId)->value('status') === 'draft'
    && DB::table('supplier_invoices')->where('id', $invoiceId)->value('approved_at') === null
    && $obligationCount($invoiceId) === 0 && $approvedAudits($invoiceId) === 0;

echo "Supplier invoice approval race on mysql://{$host}/{$actualDb} — {$sessions} sessions per scenario\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-46s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

// --- Phase 1: N sessions approve the SAME draft -------------------------------------------------------------
[$owner, $org, $ctx] = $world('P1');
$invoice = $draft($owner, $org, $ctx, 'RACE-1', '2026-11-10', '100.00', [['due_date' => '2027-01-15', 'amount' => '50.00'], ['due_date' => '2026-12-31', 'amount' => '70.00']]);
$jobs = [];
for ($i = 0; $i < $sessions; $i++) {
    $jobs[] = ['child' => 'approve', 'org' => $org->id, 'user' => $owner->id, 'invoice' => $invoice->id];
}
$results = $collect($launch($jobs, microtime(true) + 4.0));
$errors = [];
$winners = 0;
foreach ($results['approve'] as $r) {
    if (! $r['ok']) {
        $errors[] = 'approver failed (error / deadlock / timeout): '.json_encode($r);
    } elseif ($r['approved_now']) {
        $winners++;
    }
}
$winners === 1 || $errors[] = "expected exactly one session to approve now, got {$winners}";
DB::table('supplier_invoices')->where('id', $invoice->id)->value('status') === 'approved' || $errors[] = 'invoice is not approved';
$obligationCount($invoice->id) === 2 || $errors[] = 'obligations for the invoice: '.$obligationCount($invoice->id).' (expected 2)';
$numbers = DB::table('finance_obligations')->where('organization_id', $org->id)->orderBy('id')->pluck('obligation_number')->all();
$numbers === ['OBL-2026-000001', 'OBL-2027-000001'] || $errors[] = 'obligation numbers: '.json_encode($numbers);
$approvedAudits($invoice->id) === 1 || $errors[] = 'supplier_invoice.approved audit rows: '.$approvedAudits($invoice->id);
DB::table('audit_logs')->where('organization_id', $org->id)->where('event', 'finance_obligation.created')->count() === 2 || $errors[] = 'finance_obligation.created audit rows != 2';
$report("1 same draft: {$sessions} sessions, one set of payables", $errors);

// --- Phase 2A: close wins (deterministic) ------------------------------------------------------------------
[$owner, $org, $ctx] = $world('P2A');
$ids = [];
for ($i = 0; $i < $sessions; $i++) {
    $ids[] = $draft($owner, $org, $ctx, 'CLOSE-A-'.$i, '2026-09-10', (string) (100 + $i).'.00', [['due_date' => '2026-09-25', 'amount' => bcmul((string) (100 + $i), '1.2', 2)]])->id;
}
$signal = tempnam(sys_get_temp_dir(), 'sirsig');
@unlink($signal);
$jobs = [['child' => 'hold', 'org' => $org->id, 'user' => $owner->id, 'signal' => $signal]];
foreach ($ids as $id) {
    $jobs[] = ['child' => 'approve', 'org' => $org->id, 'user' => $owner->id, 'invoice' => $id, 'signal' => $signal];
}
$results = $collect($launch($jobs, microtime(true)));
$errors = [];
foreach ($results['hold'] as $r) {
    $r['ok'] || $errors[] = "holder failed: {$r['class']} {$r['message']}";
}
foreach ($results['approve'] as $r) {
    $isClosedRefusal($r) || $errors[] = 'approver was not refused with the closed-period message: '.json_encode($r);
}
foreach ($ids as $id) {
    $cleanDraft($id) || $errors[] = "invoice {$id} is not a clean draft after the refusal";
}
DB::table('finance_obligation_sequences')->where('organization_id', $org->id)->count() === 0 || $errors[] = 'an obligation number was consumed by a refused approval';
$monthStatus($org) === 'closed' || $errors[] = 'month is not closed';
@unlink($signal);
$report("2A close wins: {$sessions} approvers refused", $errors);

// --- Phase 2B: free race with the real close action ---------------------------------------------------------
[$owner, $org, $ctx] = $world('P2B');
$ids = [];
for ($i = 0; $i < $sessions; $i++) {
    $ids[] = $draft($owner, $org, $ctx, 'CLOSE-B-'.$i, '2026-09-10', (string) (100 + $i).'.00', [['due_date' => '2026-09-25', 'amount' => bcmul((string) (100 + $i), '1.2', 2)]])->id;
}
$jobs = [['child' => 'close', 'org' => $org->id, 'user' => $owner->id]];
foreach ($ids as $id) {
    $jobs[] = ['child' => 'approve', 'org' => $org->id, 'user' => $owner->id, 'invoice' => $id];
}
$results = $collect($launch($jobs, microtime(true) + 4.0));
$errors = [];
foreach ($results['close'] as $r) {
    $r['ok'] || $errors[] = "close failed: {$r['class']} {$r['message']}";
}
foreach ($results['approve'] as $r) {
    if (! $r['ok'] && ! $isClosedRefusal($r)) {
        $errors[] = 'unexpected approver outcome (deadlock / timeout / other): '.json_encode($r);
    }
}
$monthStatus($org) === 'closed' || $errors[] = 'month is not closed after the race';
$approved = 0;
foreach ($ids as $id) {
    $status = DB::table('supplier_invoices')->where('id', $id)->value('status');
    if ($status === 'approved') {
        $approved++;
        $obligationCount($id) === 1 || $errors[] = "approved invoice {$id} has {$obligationCount($id)} obligations (expected 1)";
        $approvedAudits($id) === 1 || $errors[] = "approved invoice {$id} has {$approvedAudits($id)} approval audit rows";
    } elseif (! $cleanDraft($id)) {
        $errors[] = "invoice {$id} is neither cleanly approved nor a clean draft (status {$status})";
    }
}
$numbers = DB::table('finance_obligations')->where('organization_id', $org->id)->orderBy('id')->pluck('obligation_number')->all();
$expected = array_map(fn (int $n) => sprintf('OBL-2026-%06d', $n), $approved === 0 ? [] : range(1, $approved));
$numbers === $expected || $errors[] = 'obligation numbers are not consecutive: '.json_encode($numbers);
// After the close committed NO remaining draft may be approved.
foreach ($ids as $id) {
    if (DB::table('supplier_invoices')->where('id', $id)->value('status') !== 'draft') {
        continue;
    }
    try {
        app(ApproveSupplierInvoiceAction::class)->execute($owner->fresh(), SupplierInvoice::query()->withoutGlobalScopes()->findOrFail($id), [
            'control_total_ttc' => (string) DB::table('supplier_invoices')->where('id', $id)->value('total_ttc'), 'acknowledge_unmatched' => true, 'match_note' => 'late',
        ]);
        $errors[] = "invoice {$id} was approved AFTER the month closed";
    } catch (ValidationException $e) {
        str_contains(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'clôturée') || $errors[] = 'late approval refused for another reason: '.json_encode($e->errors());
    }
}
$report('2B free race: outcomes consistent', $errors);
printf("      (approved before close: %d, refused: %d)\n", $approved, $sessions - $approved);

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

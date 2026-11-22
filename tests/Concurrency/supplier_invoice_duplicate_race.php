<?php

/**
 * MySQL concurrency regression: the hard duplicate rule of supplier invoices (Phase 2 Sprint 1 / PR9).
 *
 * N REAL SaveSupplierInvoiceDraftAction calls in separate PHP processes (separate MySQL sessions) are released at ONE
 * barrier:
 *
 *   php tests/Concurrency/supplier_invoice_duplicate_race.php [--sessions=6] [--database=commerce_erp_dev_test]
 *
 * Phase 1 — same supplier + same number (different spellings) + same year, distinct client operation ids:
 *   exactly ONE draft wins; every other session is refused with the duplicate message (never a raw SQL error /
 *   deadlock / timeout); exactly one row with that dedupe key; the loser's internal number is not consumed.
 * Phase 2 — the winner is VOIDED (frees the key) and the same race runs again: exactly one new draft wins.
 * Phase 3 — same client_operation_id from every session: exactly one draft, every session succeeds and returns it.
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases
 * only; creates its own organization. Requires a migrated database.
 */

use App\Actions\SupplierInvoices\SaveSupplierInvoiceDraftAction;
use App\Actions\SupplierInvoices\VoidSupplierInvoiceAction;
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
$options = getopt('', ['sessions::', 'database::', 'child::', 'org::', 'user::', 'ctx::', 'number::', 'op::', 'start::']);
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

$payload = function (array $ctx, string $number, ?string $op): array {
    return [
        'store_id' => $ctx['store_id'], 'supplier_id' => $ctx['supplier_id'], 'invoice_number' => $number, 'invoice_date' => '2026-11-10', 'currency_code' => 'MAD',
        'client_operation_id' => $op, 'control_total_ttc' => '120.00',
        'lines' => [['cost_nature' => 'inventory', 'product_variant_id' => $ctx['variant_id'], 'description' => 'Article', 'quantity' => '2', 'unit_price_ht' => '50.00', 'tax_rate_id' => $ctx['tax_rate_id']]],
        'installments' => [['due_date' => '2026-12-10', 'amount' => '120.00']],
    ];
};

// ───────────────────────────────────────────────────────────── child
if (isset($options['child'])) {
    Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
    $org = Organization::query()->findOrFail((int) $options['org']);
    $user = User::query()->findOrFail((int) $options['user']);
    $ctx = json_decode(base64_decode($options['ctx']), true);
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    try {
        $result = app(SaveSupplierInvoiceDraftAction::class)->execute($user, $org, $payload($ctx, $options['number'], $options['op'] ?? null));
        echo json_encode(['ok' => true, 'invoice' => $result['invoice']->id, 'created' => $result['created']]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
Carbon::setTestNow(Carbon::parse(NOW, 'UTC'));
$sessions = max(2, (int) ($options['sessions'] ?? 6));

$owner = new User;
$owner->name = 'Duplicate race';
$owner->email = 'dup-'.Str::lower(Str::random(12)).'@race.local';
$owner->password = 'race-not-a-login';
$owner->email_verified_at = now();
$owner->save();
$org = app(OrganizationCreator::class)->create($owner, 'Duplicate race');
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
$ctx = ['store_id' => $store->id, 'supplier_id' => $supplierId, 'tax_rate_id' => $tax->id, 'variant_id' => $variant->id];

$run = function (array $numbers, array $ops) use ($database, $org, $owner, $ctx): array {
    $start = microtime(true) + 4.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($numbers as $i => $number) {
        $cmd = [PHP_BINARY, __FILE__, '--child=save', '--org='.$org->id, '--user='.$owner->id, '--start='.$start, '--database='.$database, '--number='.$number, '--ctx='.base64_encode(json_encode($ctx))];
        if ($ops[$i] !== null) {
            $cmd[] = '--op='.$ops[$i];
        }
        $errFile = tempnam(sys_get_temp_dir(), 'dr');
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
$isDuplicateRefusal = fn (array $r): bool => ! $r['ok'] && $r['class'] === 'ValidationException' && str_contains($r['message'], 'existe déjà');
$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
$liveWithKey = fn (): int => SupplierInvoice::query()->withoutGlobalScopes()->where('organization_id', $org->id)->where('supplier_id', $supplierId)->where('dedupe_key', 'RACE001|2026')->count();

echo "Supplier invoice duplicate race on mysql://{$host}/{$actualDb} — {$sessions} sessions per phase\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-48s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

$spellings = ['RACE-001', 'race 001', 'Race/001', 'RACE.001', ' race-001 ', 'RACE_001'];
$numbers = [];
$ops = [];
for ($i = 0; $i < $sessions; $i++) {
    $numbers[] = $spellings[$i % count($spellings)];
    $ops[] = (string) Str::uuid();
}

// Phase 1
$results = $run($numbers, $ops);
$errors = [];
$winners = array_values(array_filter($results, fn ($r) => $r['ok']));
foreach ($results as $r) {
    if (! $r['ok'] && ! $isDuplicateRefusal($r)) {
        $errors[] = 'unexpected outcome (raw SQL error / deadlock / timeout / other): '.json_encode($r);
    }
}
count($winners) === 1 || $errors[] = 'expected exactly one winner, got '.count($winners);
$liveWithKey() === 1 || $errors[] = 'rows holding the key: '.$liveWithKey();
$internal = DB::table('supplier_invoices')->where('organization_id', $org->id)->pluck('internal_number')->all();
$internal === ['FAF-2026-000001'] || $errors[] = 'internal numbers: '.json_encode($internal);
$report("1 same number+year: one winner of {$sessions}", $errors);

// Phase 2: void frees the key
$winnerId = $winners[0]['invoice'] ?? 0;
$errors = [];
try {
    app(VoidSupplierInvoiceAction::class)->execute($owner->fresh(), SupplierInvoice::query()->withoutGlobalScopes()->findOrFail($winnerId), ['reason_code' => 'duplicate_entry']);
} catch (Throwable $e) {
    $errors[] = 'void failed: '.$e->getMessage();
}
$liveWithKey() === 0 || $errors[] = 'void did not free the key';
$ops2 = array_map(fn () => (string) Str::uuid(), $numbers);
$results = $run($numbers, $ops2);
$winners = array_values(array_filter($results, fn ($r) => $r['ok']));
foreach ($results as $r) {
    if (! $r['ok'] && ! $isDuplicateRefusal($r)) {
        $errors[] = 'unexpected outcome after void: '.json_encode($r);
    }
}
count($winners) === 1 || $errors[] = 'after void: expected exactly one new winner, got '.count($winners);
$liveWithKey() === 1 || $errors[] = 'after void: rows holding the key: '.$liveWithKey();
$report('2 void frees the key; exactly one new winner', $errors);

// Phase 3: same client operation id everywhere
$op = (string) Str::uuid();
$results = $run(array_fill(0, $sessions, 'RACE-OP'), array_fill(0, $sessions, $op));
$errors = [];
$created = 0;
$ids = [];
foreach ($results as $r) {
    if (! $r['ok']) {
        $errors[] = 'idempotent session failed: '.json_encode($r);

        continue;
    }
    $ids[$r['invoice']] = true;
    $created += $r['created'] ? 1 : 0;
}
count($ids) === 1 || $errors[] = 'sessions returned '.count($ids).' different invoices';
DB::table('supplier_invoices')->where('organization_id', $org->id)->where('client_operation_id', $op)->count() === 1 || $errors[] = 'rows with the client operation id != 1';
$report('3 same client_operation_id: one draft, all succeed', $errors);
printf("      (sessions that created it: %d)\n", $created);

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

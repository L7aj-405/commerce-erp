<?php

/**
 * MySQL concurrency regression for every document-number generator.
 *
 * Spawns N separate PHP processes (separate MySQL sessions) that each open a
 * transaction, allocate a number from the same organization's sequence, hold
 * the transaction briefly (as a real document insert would) and commit — all
 * released at the same instant. Each generator is raced twice: on first use
 * (sequence row missing) and with an existing row.
 *
 *   php tests/Concurrency/number_sequence_race.php [--sessions=4] [--rounds=1] [--only=SalesOrder] [--database=commerce_erp_dev_test]
 *
 * Pass = every session succeeds (no deadlock / lock-wait timeout), numbers
 * are unique and consecutive, and InnoDB's deadlock counter did not move.
 * Safety: local *_test / *_dev MySQL databases only; each phase creates its
 * own organization, so existing data is never touched. Requires a migrated DB.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreditNoteNumberGenerator;
use App\Services\CustomerExchangeNumberGenerator;
use App\Services\CustomerReturnNumberGenerator;
use App\Services\DeliveryNoteNumberGenerator;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\InventoryBalanceLocker;
use App\Services\InvoiceNumberGenerator;
use App\Services\OrganizationCreator;
use App\Services\PaymentNumberGenerator;
use App\Services\PaymentRefundNumberGenerator;
use App\Services\ProcurementNumberGenerator;
use App\Services\PurchaseOrderNumberGenerator;
use App\Services\QuotationNumberGenerator;
use App\Services\SalesOrderNumberGenerator;
use App\Services\StockTransferNumberGenerator;
use App\Services\TransferRequestNumberGenerator;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['sessions::', 'rounds::', 'only::', 'database::', 'child::', 'org::', 'start::']);
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
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = DB::connection();
$host = (string) $connection->getConfig('host');
$actualDb = $connection->getDatabaseName();
if ($connection->getDriverName() !== 'mysql' || ! in_array($host, ['127.0.0.1', 'localhost'], true)
    || ! preg_match('/_(test|dev)$/', $actualDb)) {
    fwrite(STDERR, "Refusing to run against [{$connection->getDriverName()}://{$host}/{$actualDb}]: local *_test / *_dev MySQL databases only.\n");
    exit(2);
}

$year = (int) now()->format('Y');
/** @var array<string, Closure(Organization): string> $generators */
$generators = [
    'SalesOrder' => fn ($o) => app(SalesOrderNumberGenerator::class)->next($o),
    'Quotation' => fn ($o) => app(QuotationNumberGenerator::class)->next($o, $year),
    'Invoice' => fn ($o) => app(InvoiceNumberGenerator::class)->next($o, $year),
    'CreditNote' => fn ($o) => app(CreditNoteNumberGenerator::class)->next($o, $year),
    'DeliveryNote' => fn ($o) => app(DeliveryNoteNumberGenerator::class)->next($o),
    'Payment' => fn ($o) => app(PaymentNumberGenerator::class)->next($o),
    'PaymentRefund' => fn ($o) => app(PaymentRefundNumberGenerator::class)->next($o),
    'CustomerReturn' => fn ($o) => app(CustomerReturnNumberGenerator::class)->next($o, $year),
    'CustomerExchange' => fn ($o) => app(CustomerExchangeNumberGenerator::class)->next($o, $year),
    'Procurement' => fn ($o) => app(ProcurementNumberGenerator::class)->next($o),
    'PurchaseOrder' => fn ($o) => app(PurchaseOrderNumberGenerator::class)->next($o),
    'StockTransfer' => fn ($o) => app(StockTransferNumberGenerator::class)->next($o),
    'TransferRequest' => fn ($o) => app(TransferRequestNumberGenerator::class)->next($o),
    'FinanceObligation' => fn ($o) => app(FinanceNumberGenerator::class)->nextObligation($o, $year),
    'FinanceOutgoingPayment' => fn ($o) => app(FinanceNumberGenerator::class)->nextOutgoingPayment($o, $year),
    'FinanceRecurringTemplate' => fn ($o) => app(FinanceNumberGenerator::class)->nextRecurringTemplate($o, $year),
    'FinanceSupplierPlan' => fn ($o) => app(FinanceNumberGenerator::class)->nextSupplierInstallmentPlan($o, $year),
    'FinanceInstrument' => fn ($o) => app(FinanceNumberGenerator::class)->nextPaymentInstrument($o, $year, 'cheque'),
    // Not a number generator: same create-if-missing + FOR UPDATE row shape.
    // Each session increments on_hand by 1 under the lock, so a lost update
    // shows up as a duplicate / gap exactly like a duplicate document number.
    'InventoryBalance' => function ($o) {
        $warehouse = Warehouse::query()->where('organization_id', $o->id)->where('code', 'RACE')->firstOrFail();
        $variant = ProductVariant::query()->where('organization_id', $o->id)->where('sku', 'RACE-BAL')->firstOrFail();
        $balance = app(InventoryBalanceLocker::class)->lock($o, $warehouse, $variant);
        $balance->on_hand = InventoryQuantity::add($balance->on_hand, '1');
        $balance->save();

        return 'BAL-'.(int) $balance->on_hand;
    },
];

// ───────────────────────────────────────────────────────────── child mode
if (isset($options['child'])) {
    $org = Organization::query()->findOrFail((int) $options['org']);
    $generate = $generators[$options['child']];
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    try {
        $number = DB::transaction(function () use ($generate, $org) {
            $number = $generate($org);
            usleep(30000); // hold the lock like a document insert would

            return $number;
        });
        echo json_encode(['ok' => true, 'number' => $number]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage(), 200)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent mode
$sessions = max(2, (int) ($options['sessions'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 1));
if (isset($options['only'])) {
    $generators = array_intersect_key($generators, array_flip(explode(',', $options['only'])));
}

$newOrg = function (string $label): Organization {
    $owner = new User;
    $owner->name = "Seq race {$label}";
    $owner->email = 'seq-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->save();

    $org = app(OrganizationCreator::class)->create($owner, "Seq race {$label}");

    // Fixture for the InventoryBalance target (balance row itself starts missing).
    $warehouse = new Warehouse;
    $warehouse->organization_id = $org->id;
    $warehouse->name = 'Race';
    $warehouse->code = 'RACE';
    $warehouse->status = 'active';
    $warehouse->save();
    $product = new Product;
    $product->organization_id = $org->id;
    $product->name = 'Race balance';
    $product->status = 'active';
    $product->save();
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $product->id;
    $variant->sku = 'RACE-BAL';
    $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = '1.0000';
    $variant->status = 'active';
    $variant->save();

    return $org;
};

$race = function (string $generator, Organization $org) use ($sessions, $database): array {
    $start = microtime(true) + 3.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    for ($i = 0; $i < $sessions; $i++) {
        $errFile = tempnam(sys_get_temp_dir(), 'seq');
        $cmd = [PHP_BINARY, __FILE__, "--child={$generator}", "--org={$org->id}", "--start={$start}", "--database={$database}"];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start child process.');
        }
        $procs[] = [$proc, $pipes, $errFile];
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

$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
$sequenceOf = fn (string $number): int => (int) preg_replace('/\D/', '', preg_replace('/\/\d{4}$|^[A-Z]+-\d{4}-/', '', $number));

echo "Number-sequence race on mysql://{$host}/{$actualDb} — {$sessions} sessions, {$rounds} round(s)\n";
$before = $deadlocks();
$failures = 0;
foreach ($generators as $name => $generate) {
    for ($round = 1; $round <= $rounds; $round++) {
        $org = $newOrg("{$name} {$round}");
        foreach (['first-use' => false, 'existing' => true] as $phase => $prewarm) {
            // Existing phase: one committed allocation first, so the row exists
            // and the race must continue right after it.
            $first = $prewarm ? $sequenceOf(DB::transaction(fn () => $generate($org))) + 1 : 1;
            $results = $race($name, $org);
            $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
            $numbers = array_column($ok, 'number');
            $seq = array_map($sequenceOf, $numbers);
            sort($seq);
            $expected = range($first, $first + $sessions - 1);
            $errors = [];
            foreach ($results as $r) {
                if (! $r['ok']) {
                    $errors[] = "{$r['class']}: {$r['message']}";
                }
            }
            if (count(array_unique($numbers)) !== count($numbers)) {
                $errors[] = 'DUPLICATE numbers: '.implode(', ', $numbers);
            }
            if ($errors === [] && $seq !== $expected) {
                $errors[] = 'non-consecutive: got ['.implode(',', $seq).'] expected ['.implode(',', $expected).']';
            }
            printf("  %-25s r%d %-9s %s  %s\n", $name, $round, $phase, $errors === [] ? 'PASS' : 'FAIL', implode(' ', $numbers));
            foreach (array_unique($errors) as $error) {
                echo "      ✗ {$error}\n";
            }
            $failures += $errors === [] ? 0 : 1;
        }
    }
}
$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

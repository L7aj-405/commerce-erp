<?php

/**
 * MySQL concurrency regression for the Phase 4 Test (sandbox) inventory ledger.
 *
 *   php tests/Concurrency/sandbox_inventory_race.php [--sessions=8] [--database=commerce_erp_dev_test]
 *
 * Separate PHP processes (separate MySQL sessions) are released at the same
 * instant. Scenarios:
 *  - reserve:   N Test sessions reserve 1 unit each from a Test balance of 5
 *               → exactly 5 succeed, no oversell, Test reserved = 5.
 *  - first-use: N Test sessions lock-and-increment a Test balance row that does
 *               not exist yet → no lost update (on_hand = N), no deadlock.
 *  - mixed:     Production and Test sessions reserve the SAME warehouse/variant
 *               concurrently → each ledger only sees its own reservations.
 * In every scenario the Production ledger must end exactly as expected and
 * InnoDB's deadlock counter must not move. Local *_test / *_dev DBs only;
 * each run creates its own organization.
 */

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sandbox;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryBalanceLocker;
use App\Services\InventoryReservationManager;
use App\Services\OrganizationCreator;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxInventoryInitializer;
use App\Support\InventoryQuantity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['sessions::', 'database::', 'child::', 'org::', 'start::', 'env::', 'user::']);
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

/** Force the request-scoped Test context in a CLI process (test harness only). */
$useSandbox = function (?Sandbox $sandbox): void {
    $context = app(SandboxContext::class);
    Closure::bind(function () use ($sandbox) {
        $this->sandbox = $sandbox;
        $this->selected = $sandbox !== null;
    }, $context, SandboxContext::class)();
};

$fixture = fn (Organization $org): array => [
    Warehouse::query()->where('organization_id', $org->id)->where('code', 'RACE')->firstOrFail(),
    ProductVariant::query()->where('organization_id', $org->id)->where('sku', 'RACE-STOCK')->firstOrFail(),
    ProductVariant::query()->where('organization_id', $org->id)->where('sku', 'RACE-NEW')->firstOrFail(),
];

// ───────────────────────────────────────────────────────────── child mode
if (isset($options['child'])) {
    $org = Organization::query()->findOrFail((int) $options['org']);
    $actor = User::query()->findOrFail((int) $options['user']);
    [$warehouse, $stocked, $fresh] = $fixture($org);
    if (($options['env'] ?? 'test') === 'test') {
        $useSandbox(Sandbox::query()->where('organization_id', $org->id)->firstOrFail());
    }
    while (microtime(true) < (float) $options['start']) {
        usleep(100);
    }
    try {
        $result = match ($options['child']) {
            'reserve' => app(InventoryReservationManager::class)->reserve($actor, $org, $warehouse, $stocked, '1', reference: 'race')->getTable(),
            'first-use' => DB::transaction(function () use ($org, $warehouse, $fresh) {
                $balance = app(InventoryBalanceLocker::class)->lock($org, $warehouse, $fresh);
                $balance->on_hand = InventoryQuantity::add($balance->on_hand, '1');
                $balance->save();
                usleep(30000);

                return $balance->getTable();
            }),
        };
        echo json_encode(['ok' => true, 'table' => $result]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage(), 200)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent mode
$sessions = max(6, (int) ($options['sessions'] ?? 8));

$owner = new User;
$owner->name = 'Sandbox race';
$owner->email = 'sbx-'.Str::lower(Str::random(12)).'@race.local';
$owner->password = 'race-not-a-login';
$owner->save();
$org = app(OrganizationCreator::class)->create($owner, 'Sandbox race '.Str::random(6));
$owner->active_organization_id = $org->id;
$owner->save();
$warehouse = new Warehouse;
$warehouse->organization_id = $org->id;
$warehouse->name = 'Race';
$warehouse->code = 'RACE';
$warehouse->status = 'active';
$warehouse->save();
foreach (['RACE-STOCK', 'RACE-NEW'] as $sku) {
    $product = new Product;
    $product->organization_id = $org->id;
    $product->name = $sku;
    $product->status = 'active';
    $product->save();
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $product->id;
    $variant->sku = $sku;
    $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = '1.0000';
    $variant->status = 'active';
    $variant->save();
}
[$warehouse, $stocked, $fresh] = $fixture($org);
DB::table('inventory_balances')->insert(['organization_id' => $org->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $stocked->id,
    'on_hand' => '5.0000', 'reserved' => '0.0000', 'created_at' => now(), 'updated_at' => now()]);
$sandbox = new Sandbox;
$sandbox->organization_id = $org->id;
$sandbox->name = 'Test';
$sandbox->status = 'active';
$sandbox->generation = 1;
$sandbox->created_by_user_id = $owner->id;
$sandbox->save();
app(SandboxInventoryInitializer::class)->ensure($sandbox);

$race = function (string $child, array $envs) use ($org, $owner, $database): array {
    $start = microtime(true) + 3.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($envs as $mode) {
        $errFile = tempnam(sys_get_temp_dir(), 'sbx');
        $cmd = [PHP_BINARY, __FILE__, "--child={$child}", "--org={$org->id}", "--start={$start}", "--database={$database}", "--env={$mode}", "--user={$owner->id}"];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
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
$num = fn ($value): string => InventoryQuantity::normalize((string) $value);
$testRow = fn (int $variantId) => DB::table('sandbox_inventory_balances')->where('sandbox_id', $sandbox->id)->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variantId)->first();
$prodRow = fn (int $variantId) => DB::table('inventory_balances')->where('organization_id', $org->id)->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variantId)->first();

echo "Sandbox inventory race on mysql://{$host}/{$actualDb} — {$sessions} sessions\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $name, array $errors) use (&$failures) {
    printf("  %-10s %s\n", $name, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      ✗ {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

// reserve: no oversell on the Test ledger, Production untouched.
$results = $race('reserve', array_fill(0, $sessions, 'test'));
$ok = array_filter($results, fn ($r) => $r['ok']);
$errors = [];
if (count($ok) !== 5) {
    $errors[] = 'expected exactly 5 successful Test reservations, got '.count($ok).' '.json_encode(array_filter($results, fn ($r) => ! $r['ok'] && $r['class'] !== 'ValidationException'));
}
if (array_unique(array_column($ok, 'table')) !== ['sandbox_inventory_reservations']) {
    $errors[] = 'a Test reservation was not written to the sandbox ledger';
}
if ($num($testRow($stocked->id)->reserved) !== '5.0000') {
    $errors[] = 'Test reserved = '.$testRow($stocked->id)->reserved;
}
if ($num($prodRow($stocked->id)->reserved) !== '0.0000' || $num($prodRow($stocked->id)->on_hand) !== '5.0000') {
    $errors[] = 'Production balance changed';
}
$report('reserve', $errors);

// first-use: concurrent create-and-lock of a missing Test balance row.
$results = $race('first-use', array_fill(0, $sessions, 'test'));
$errors = array_map(fn ($r) => "{$r['class']}: {$r['message']}", array_filter($results, fn ($r) => ! $r['ok']));
if ($errors === [] && $num($testRow($fresh->id)?->on_hand ?? 0) !== $num($sessions)) {
    $errors[] = 'lost update: Test on_hand = '.($testRow($fresh->id)?->on_hand ?? 'missing');
}
if ($prodRow($fresh->id) !== null) {
    $errors[] = 'a Production balance row was created by a Test session';
}
$report('first-use', $errors);

// mixed: Production and Test reserve the same warehouse/variant at once.
DB::table('sandbox_inventory_reservations')->where('sandbox_id', $sandbox->id)->update(['status' => 'released']);
DB::table('sandbox_inventory_balances')->where('sandbox_id', $sandbox->id)->where('product_variant_id', $stocked->id)->update(['reserved' => 0]);
$half = intdiv($sessions, 2);
$results = $race('reserve', [...array_fill(0, $half, 'prod'), ...array_fill(0, $half, 'test')]);
$errors = [];
$prodOk = count(array_filter(array_slice($results, 0, $half), fn ($r) => $r['ok'] && $r['table'] === 'inventory_reservations'));
$testOk = count(array_filter(array_slice($results, $half), fn ($r) => $r['ok'] && $r['table'] === 'sandbox_inventory_reservations'));
if ($prodOk !== min($half, 5) || $testOk !== min($half, 5)) {
    $errors[] = 'expected '.min($half, 5)." per environment, got prod={$prodOk} test={$testOk} ".json_encode($results);
}
if ($num($prodRow($stocked->id)->reserved) !== $num($prodOk) || $num($testRow($stocked->id)->reserved) !== $num($testOk)) {
    $errors[] = 'reserved totals leaked across environments';
}
if (DB::table('inventory_reservations')->where('organization_id', $org->id)->count() !== $prodOk) {
    $errors[] = 'Production reservation count mismatch';
}
$report('mixed', $errors);

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

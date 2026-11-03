<?php

/**
 * MySQL concurrency regression for the SELECTIVE data reset.
 *
 * PHPUnit runs every test in one transaction and cannot race two sessions;
 * this script spawns separate PHP processes (separate MySQL connections)
 * released at the same instant, then checks the invariants.
 *
 *   php tests/Concurrency/selective_reset_race.php [--rounds=4] [--database=commerce_erp_dev_test]
 *
 * Safety: local MySQL only, database name must end in `_test` or `_dev`;
 * each round creates its own organization and never touches other data.
 *
 * Scenarios (Showroom stock 10, POS orders reserving from it):
 *   double_execute    the same approved plan executed by two sessions at once
 *                     → exactly one succeeds, the other is refused cleanly
 *                     (stale / already executed), nothing half-deleted.
 *   reset_vs_confirm  selective reset of order A races the confirmation of
 *                     order B on the SAME stock balance → the reset either
 *                     completes or is refused as stale; B always confirms.
 *
 * Every round checks: reserved = active reservations, on-hand unchanged,
 * no orphan allocation, and no deadlock / lock-wait timeout.
 */

use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderSource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrganizationCreator;
use App\Services\OrganizationDataReset\Selective\SelectiveResetCategoryRegistry;
use App\Services\OrganizationDataReset\Selective\SelectiveResetExecutor;
use App\Services\OrganizationDataReset\Selective\SelectiveResetPlanner;
use App\Services\OrganizationDataReset\Selective\SelectiveResetSelection;
use App\Services\StoreCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['rounds::', 'database::', 'child::', 'payload::', 'start::']);
$database = $options['database'] ?? 'commerce_erp_dev_test';

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'LOG_CHANNEL' => 'null',
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
if ($connection->getDriverName() !== 'mysql' || ! in_array($host, ['127.0.0.1', 'localhost'], true) || ! preg_match('/_(test|dev)$/', $actualDb)) {
    fwrite(STDERR, "Refusing to run against [{$connection->getDriverName()}://{$host}/{$actualDb}]: local *_test / *_dev MySQL databases only.\n");
    exit(2);
}

$selectionOf = fn (array $data) => SelectiveResetSelection::fromArray($data, app(SelectiveResetCategoryRegistry::class));

// ───────────────────────────────────────────────────────────── child mode
if (isset($options['child'])) {
    $p = json_decode((string) $options['payload'], true);
    $owner = User::query()->findOrFail($p['owner']);
    $org = Organization::query()->findOrFail($p['org']);
    while (microtime(true) < (float) $options['start']) {
        usleep(200);
    }
    $t = microtime(true);
    try {
        $result = match ($options['child']) {
            'reset' => 'deleted='.array_sum(app(SelectiveResetExecutor::class)->execute($org, $owner, $selectionOf($p['selection']), $p['fingerprint'])['deleted']),
            'confirm' => app(ConfirmSalesOrderAction::class)->execute($owner, SalesOrder::query()->findOrFail($p['order']))->status->value,
        };
        echo json_encode(['ok' => true, 'result' => $result, 'ms' => (int) round((microtime(true) - $t) * 1000)]);
    } catch (Throwable $e) {
        $cause = $e->getPrevious() ? ' ← '.$e->getPrevious()::class.': '.$e->getPrevious()->getMessage() : '';
        echo json_encode(['ok' => false, 'class' => $e::class, 'message' => Str::limit($e->getMessage().$cause, 400), 'ms' => (int) round((microtime(true) - $t) * 1000)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent mode
$rounds = max(1, (int) ($options['rounds'] ?? 4));

$world = function (int $round, string $scenario): array {
    $owner = new User;
    $owner->name = "Reset race {$scenario} {$round}";
    $owner->email = 'reset-race-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Reset race {$scenario} #{$round}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Showroom', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    $show = new Warehouse;
    $show->forceFill(['organization_id' => $org->id, 'name' => 'Showroom', 'code' => 'SHOW', 'status' => 'active'])->save();
    $product = new Product;
    $product->forceFill(['organization_id' => $org->id, 'name' => 'Shure SM58', 'status' => 'active'])->save();
    $variant = new ProductVariant;
    $variant->forceFill(['organization_id' => $org->id, 'product_id' => $product->id, 'sku' => 'SM58', 'default_sale_price' => '100.0000', 'regular_sale_price' => '100.0000', 'unit_price_ht' => '100.0000', 'status' => 'active'])->save();
    app(OpeningStockAction::class)->execute($owner, $org, $show, $variant, '10.0000', 'race');

    return compact('owner', 'org', 'store', 'show', 'variant');
};

$draft = function (array $w, string $qty): SalesOrder {
    $order = app(CreateSalesOrderAction::class)->execute($w['owner'], $w['org'], $w['store'],
        ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null], SalesOrderSource::Pos);
    $order->forceFill(['pos_warehouse_id' => $w['show']->id, 'pos_global_discount_type' => 'none', 'pos_global_discount_value' => '0.0000',
        'pos_fulfillment_mode' => 'pickup', 'pos_shipping_fee' => '0.0000'])->save();
    app(SaveSalesOrderLineAction::class)->execute($w['owner'], $order->fresh(), ['line_type' => 'catalog', 'product_variant_id' => $w['variant']->id,
        'warehouse_id' => $w['show']->id, 'quantity' => $qty, 'discount_type' => 'none', 'discount_value' => '0.0000']);

    return $order;
};

$race = function (array $jobs) use ($database): array {
    $start = microtime(true) + 3.0;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as [$mode, $payload]) {
        $cmd = [PHP_BINARY, __FILE__, "--child={$mode}", '--payload='.json_encode($payload), "--start={$start}", "--database={$database}"];
        $errFile = tempnam(sys_get_temp_dir(), 'race');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        $procs[] = [$proc, $pipes, $errFile];
    }
    $results = [];
    foreach ($procs as [$proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out."\n".$err), 400), 'ms' => 0];
    }

    return $results;
};

$invariants = function (array $w, string $onHandBefore): array {
    $errors = [];
    $orgId = $w['org']->id;
    $balance = DB::table('inventory_balances')->where('organization_id', $orgId)->first();
    $active = (string) DB::table('inventory_reservations')->where('organization_id', $orgId)->where('status', 'active')->sum('quantity');
    if (abs((float) $balance->reserved - (float) $active) > 0.00001) {
        $errors[] = "reserved={$balance->reserved} but active reservations={$active}";
    }
    if ((string) $balance->on_hand !== $onHandBefore) {
        $errors[] = "on_hand changed {$onHandBefore} → {$balance->on_hand}";
    }
    $orphans = DB::table('sales_order_inventory_allocations as a')->leftJoin('sales_order_lines as l', 'l.id', '=', 'a.sales_order_line_id')
        ->where('a.organization_id', $orgId)->whereNull('l.id')->count();
    if ($orphans > 0) {
        $errors[] = "{$orphans} orphan allocation(s)";
    }

    return $errors;
};

$lockMetrics = fn (): array => collect(DB::select("SELECT name, `count` FROM information_schema.INNODB_METRICS WHERE name IN ('lock_deadlocks', 'lock_timeouts')"))
    ->mapWithKeys(fn ($r) => [$r->name => (int) $r->count])->all();

$scenarios = [
    'double_execute' => function (int $round) use ($world, $draft, $race, $selectionOf): array {
        $w = $world($round, 'double_execute');
        foreach (['2.0000', '3.0000'] as $qty) {
            app(ConfirmSalesOrderAction::class)->execute($w['owner'], $draft($w, $qty)->fresh());
        }
        $onHand = (string) DB::table('inventory_balances')->where('organization_id', $w['org']->id)->value('on_hand');
        $selection = ['categories' => ['sales_orders']];
        $plan = app(SelectiveResetPlanner::class)->plan($w['org'], $selectionOf($selection));
        $payload = ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'selection' => $selection, 'fingerprint' => $plan->fingerprint];
        $children = $race([['reset', $payload], ['reset', $payload]]);
        $errors = [];
        $ok = count(array_filter($children, fn ($c) => $c['ok']));
        if ($ok !== 1) {
            $errors[] = "{$ok} executions succeeded (expected exactly 1)";
        }
        foreach ($children as $c) {
            if (! $c['ok'] && ! str_contains($c['class'], 'OrganizationDataReset')) {
                $errors[] = "loser failed uncleanly: {$c['class']}: {$c['message']}";
            }
        }
        if (SalesOrder::query()->withoutGlobalScopes()->where('organization_id', $w['org']->id)->exists()) {
            $errors[] = 'orders left behind';
        }

        return [$w, $onHand, $children, $errors, "ok={$ok}"];
    },
    'reset_vs_confirm' => function (int $round) use ($world, $draft, $race, $selectionOf): array {
        $w = $world($round, 'reset_vs_confirm');
        $a = app(ConfirmSalesOrderAction::class)->execute($w['owner'], $draft($w, '2.0000')->fresh());
        $b = $draft($w, '3.0000');
        $onHand = (string) DB::table('inventory_balances')->where('organization_id', $w['org']->id)->value('on_hand');
        $selection = ['categories' => ['sales_orders'], 'records' => ['sales_orders' => [$a->id]]];
        $plan = app(SelectiveResetPlanner::class)->plan($w['org'], $selectionOf($selection));
        $children = $race([
            ['reset', ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'selection' => $selection, 'fingerprint' => $plan->fingerprint]],
            ['confirm', ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'order' => $b->id]],
        ]);
        $errors = [];
        if (! $children[1]['ok']) {
            $errors[] = "order B failed to confirm: {$children[1]['class']}: {$children[1]['message']}";
        }
        $aExists = SalesOrder::query()->withoutGlobalScopes()->whereKey($a->id)->exists();
        if ($children[0]['ok'] === $aExists) {
            $errors[] = 'reset outcome and order A state disagree';
        }
        if (! $children[0]['ok'] && ! str_contains($children[0]['class'], 'StalePlan')) {
            $errors[] = "reset refused with {$children[0]['class']}: {$children[0]['message']} (expected success or stale)";
        }

        return [$w, $onHand, $children, $errors, $children[0]['ok'] ? 'reset-first' : 'stale'];
    },
];

echo "Selective reset race on mysql://{$host}/{$actualDb} — {$rounds} round(s) per scenario\n";
$before = $lockMetrics();
$failures = 0;
$total = 0;
foreach ($scenarios as $name => $scenario) {
    for ($round = 1; $round <= $rounds; $round++) {
        $total++;
        [$w, $onHand, $children, $errors, $summary] = $scenario($round);
        $errors = array_merge($errors, $invariants($w, $onHand));
        $timing = implode(' | ', array_map(fn ($c) => ($c['ok'] ? ($c['result'] ?? 'ok') : class_basename($c['class'])).' '.$c['ms'].'ms', $children));
        printf("  %-17s #%-2d %s  %-12s [%s]\n", $name, $round, $errors === [] ? 'PASS' : 'FAIL', $summary, $timing);
        foreach ($errors as $error) {
            echo "      ✗ {$error}\n";
        }
        $failures += $errors === [] ? 0 : 1;
    }
}
$after = $lockMetrics();
$deadlocks = ($after['lock_deadlocks'] ?? 0) - ($before['lock_deadlocks'] ?? 0);
$timeouts = ($after['lock_timeouts'] ?? 0) - ($before['lock_timeouts'] ?? 0);
echo "InnoDB during run (server-wide): deadlocks +{$deadlocks}, lock-wait timeouts +{$timeouts}\n";
if ($deadlocks > 0 || $timeouts > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." — {$total} round(s), {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);

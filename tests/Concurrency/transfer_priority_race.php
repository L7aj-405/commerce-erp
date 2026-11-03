<?php

/**
 * MySQL concurrency regression for transfer requests & showroom replenishment.
 *
 * PHPUnit wraps every test in one transaction, so it cannot exercise two
 * real sessions racing on row locks. This script does: it spawns separate
 * PHP processes (separate MySQL connections) released at the same instant,
 * then checks the inventory invariants.
 *
 *   php tests/Concurrency/transfer_priority_race.php [--rounds=6] [--database=commerce_erp_dev_test]
 *
 * Safety: refuses any host other than 127.0.0.1/localhost and any database
 * whose name does not end in `_test` or `_dev`. Each round creates its own
 * isolated organization, so it never touches existing data. The database must
 * already be migrated (e.g. by `phpunit -c phpunit.mysql.xml` or `migrate`).
 *
 * Scenarios (Shure BETA 58A: showroom 1, depot 4, showroom minimum 2):
 *   confirm_vs_sweep   POS order of 3 confirmed while the minimum sweep runs
 *                      (even rounds start with the general 1-unit request).
 *                      → customer request = 2 customer + 2 replenishment,
 *                        4 in total from the depot, nothing left in general.
 *   orders_compete     two POS orders of 3 confirmed at once; 5 units exist
 *                      → exactly one confirms, the other is rejected cleanly.
 *   sweep_vs_sweep     three minimum sweeps at once → exactly one 1-unit line.
 *
 * Every round also checks: reserved ≤ on_hand, balances = active
 * reservations, no depot over-promise, and no deadlock / lock-wait timeout
 * (child errors + InnoDB lock_deadlocks / lock_timeouts metrics).
 * Exit code 0 = all rounds passed.
 */

use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Inventory\PlanShowroomReplenishmentAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderSource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentOverride;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\OrganizationCreator;
use App\Services\ShowroomReplenishmentPlanner;
use App\Services\StoreCreator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 2);
$options = getopt('', ['rounds::', 'database::', 'child::', 'payload::', 'start::']);
$database = $options['database'] ?? 'commerce_erp_dev_test';

$overrides = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
foreach ($overrides as $key => $value) {
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

// ───────────────────────────────────────────────────────────── child mode
if (isset($options['child'])) {
    $ids = json_decode((string) $options['payload'], true);
    $owner = User::query()->findOrFail($ids['owner']);
    $org = Organization::query()->findOrFail($ids['org']);
    while (microtime(true) < (float) $options['start']) {
        usleep(200);
    }
    $t = microtime(true);
    try {
        $result = match ($options['child']) {
            'confirm' => app(ConfirmSalesOrderAction::class)->execute($owner, SalesOrder::query()->findOrFail($ids['order']))->status->value,
            'sweep' => 'touched='.count(app(PlanShowroomReplenishmentAction::class)->execute($owner, $org, Warehouse::query()->findOrFail($ids['show']))),
        };
        echo json_encode(['ok' => true, 'result' => $result, 'ms' => (int) round((microtime(true) - $t) * 1000)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => $e::class, 'message' => Str::limit($e->getMessage(), 300),
            'ms' => (int) round((microtime(true) - $t) * 1000)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent mode
if (! DB::getSchemaBuilder()->hasTable('transfer_request_lines')) {
    fwrite(STDERR, "[{$actualDb}] is not migrated. Run the MySQL test suite or `migrate` against it first.\n");
    exit(2);
}

$rounds = max(1, (int) ($options['rounds'] ?? 6));
$planner = app(ShowroomReplenishmentPlanner::class);

$world = function (int $round, string $scenario): array {
    $owner = new User;
    $owner->name = "Race owner {$scenario} {$round}";
    $owner->email = 'race-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Race {$scenario} #{$round}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Showroom', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();

    $warehouse = function (string $name, string $code) use ($org): Warehouse {
        $w = new Warehouse;
        $w->organization_id = $org->id;
        $w->name = $name;
        $w->code = $code;
        $w->status = 'active';
        $w->save();

        return $w;
    };
    $show = $warehouse('Showroom', 'SHOW');
    $depot = $warehouse('Dépôt SP', 'SP');

    $product = new Product;
    $product->organization_id = $org->id;
    $product->name = 'Shure BETA 58A';
    $product->status = 'active';
    $product->save();
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $product->id;
    $variant->sku = 'SHU-BETA58A';
    $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = '100.0000';
    $variant->status = 'active';
    $variant->save();
    app(OpeningStockAction::class)->execute($owner, $org, $show, $variant, '1.0000', 'race');
    app(OpeningStockAction::class)->execute($owner, $org, $depot, $variant, '4.0000', 'race');

    $setting = new WarehouseReplenishmentSetting;
    $setting->organization_id = $org->id;
    $setting->warehouse_id = $show->id;
    $setting->auto_replenish = true;
    $setting->default_minimum_quantity = '0.0000';
    $setting->save();
    $override = new WarehouseReplenishmentOverride;
    $override->organization_id = $org->id;
    $override->warehouse_id = $show->id;
    $override->product_variant_id = $variant->id;
    $override->minimum_quantity = '2.0000';
    $override->save();

    return compact('owner', 'org', 'store', 'show', 'depot', 'variant');
};

$posOrder = function (array $w, string $qty): SalesOrder {
    $order = app(CreateSalesOrderAction::class)->execute($w['owner'], $w['org'], $w['store'],
        ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null], SalesOrderSource::Pos);
    $order->pos_warehouse_id = $w['show']->id;
    $order->pos_global_discount_type = 'none';
    $order->pos_global_discount_value = '0.0000';
    $order->pos_fulfillment_mode = 'pickup';
    $order->pos_shipping_fee = '0.0000';
    $order->save();
    app(SaveSalesOrderLineAction::class)->execute($w['owner'], $order->fresh(), ['line_type' => 'catalog',
        'product_variant_id' => $w['variant']->id, 'warehouse_id' => $w['show']->id, 'quantity' => $qty,
        'discount_type' => 'none', 'discount_value' => '0.0000']);

    return $order;
};

/** @param list<array{0: string, 1: array}> $jobs  [mode, payload] @return list<array> */
$race = function (array $jobs) use ($database): array {
    $start = microtime(true) + 3.0; // let every child finish booting Laravel first
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as [$mode, $payload]) {
        $cmd = [PHP_BINARY, __FILE__, "--child={$mode}", '--payload='.json_encode($payload), "--start={$start}", "--database={$database}"];
        $errFile = tempnam(sys_get_temp_dir(), 'race');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException("Could not start child process [{$mode}].");
        }
        $procs[] = [$proc, $pipes, $errFile];
    }
    $results = [];
    foreach ($procs as [$proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[] = json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out."\n".$err), 400)];
    }

    return $results;
};

$lockMetrics = fn (): array => collect(DB::select("SELECT name, `count` FROM information_schema.INNODB_METRICS WHERE name IN ('lock_deadlocks', 'lock_timeouts')"))
    ->mapWithKeys(fn ($r) => [$r->name => (int) $r->count])->all();

$activeLines = function (array $w): array {
    $by = [];
    foreach (TransferRequest::query()->where('organization_id', $w['org']->id)->where('source_warehouse_id', $w['depot']->id)
        ->whereIn('status', ShowroomReplenishmentPlanner::ACTIVE_STATUSES)->with('lines')->get() as $r) {
        foreach ($r->lines as $l) {
            $key = ($r->sales_order_id ? 'order:' : 'general:').$l->reason->value;
            $by[$key] = ($by[$key] ?? 0) + (float) $l->quantity;
        }
    }
    ksort($by);

    return $by;
};

$stockInvariants = function (array $w) use ($planner): array {
    $errors = [];
    foreach (DB::table('inventory_balances')->where('organization_id', $w['org']->id)->get() as $b) {
        $active = (float) DB::table('inventory_reservations')->where('organization_id', $w['org']->id)
            ->where('warehouse_id', $b->warehouse_id)->where('product_variant_id', $b->product_variant_id)
            ->where('status', 'active')->sum('quantity');
        if ((float) $b->reserved > (float) $b->on_hand || (float) $b->reserved < 0 || (float) $b->on_hand < 0) {
            $errors[] = "balance wh#{$b->warehouse_id}: on_hand={$b->on_hand} reserved={$b->reserved}";
        }
        if (abs($active - (float) $b->reserved) > 0.00001) {
            $errors[] = "wh#{$b->warehouse_id}: balance.reserved={$b->reserved} but active reservations={$active}";
        }
    }
    $shortfalls = $planner->promiseShortfalls($w['org']->id, $w['depot']->id, [$w['variant']->id]);
    if ($shortfalls !== []) {
        $errors[] = 'depot over-promised: '.json_encode($shortfalls);
    }

    return $errors;
};

$scenarios = [
    'confirm_vs_sweep' => function (int $round) use ($world, $posOrder, $race, $activeLines): array {
        $w = $world($round, 'confirm_vs_sweep');
        if ($round % 2 === 0) {
            app(PlanShowroomReplenishmentAction::class)->execute($w['owner'], $w['org'], $w['show']);
        }
        $order = $posOrder($w, '3.0000');
        $ids = ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'show' => $w['show']->id, 'order' => $order->id];
        $children = $race([['confirm', $ids], ['sweep', $ids]]);
        $errors = [];
        foreach ($children as $c) {
            if (! $c['ok']) {
                $errors[] = "child failed: {$c['class']}: {$c['message']}";
            }
        }
        $lines = $activeLines($w);
        $expected = ['order:minimum_replenishment' => 2.0, 'order:order_fulfillment' => 2.0];
        if ($lines !== $expected) {
            $errors[] = 'depot demand '.json_encode($lines).' ≠ '.json_encode($expected);
        }
        if ($order->fresh()->status->value !== 'confirmed') {
            $errors[] = 'order not confirmed';
        }

        return [$w, $children, $errors, ($round % 2 === 0 ? 'pre-general ' : 'no-general  ').json_encode($lines)];
    },
    'orders_compete' => function (int $round) use ($world, $posOrder, $race, $activeLines): array {
        $w = $world($round, 'orders_compete');
        $a = $posOrder($w, '3.0000');
        $b = $posOrder($w, '3.0000');
        $base = ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'show' => $w['show']->id];
        $children = $race([['confirm', $base + ['order' => $a->id]], ['confirm', $base + ['order' => $b->id]]]);
        $errors = [];
        $confirmed = collect([$a, $b])->filter(fn ($o) => $o->fresh()->status->value === 'confirmed')->count();
        if ($confirmed !== 1) {
            $errors[] = "{$confirmed} orders confirmed; exactly 1 can be served from 5 units";
        }
        foreach ($children as $c) {
            if (! $c['ok'] && $c['class'] !== ValidationException::class) {
                $errors[] = "loser failed uncleanly: {$c['class']}: {$c['message']}";
            }
        }
        $lines = $activeLines($w);
        if (($lines['order:order_fulfillment'] ?? 0) !== 2.0) {
            $errors[] = 'customer transfer demand '.json_encode($lines).' (expected 2 order_fulfillment)';
        }

        return [$w, $children, $errors, "confirmed={$confirmed} ".json_encode($lines)];
    },
    'sweep_vs_sweep' => function (int $round) use ($world, $race, $activeLines): array {
        $w = $world($round, 'sweep_vs_sweep');
        $ids = ['owner' => $w['owner']->id, 'org' => $w['org']->id, 'show' => $w['show']->id];
        $children = $race([['sweep', $ids], ['sweep', $ids], ['sweep', $ids]]);
        $errors = [];
        foreach ($children as $c) {
            if (! $c['ok']) {
                $errors[] = "child failed: {$c['class']}: {$c['message']}";
            }
        }
        $lines = $activeLines($w);
        if ($lines !== ['general:minimum_replenishment' => 1.0]) {
            $errors[] = 'general demand '.json_encode($lines).' (expected exactly 1 unit, no duplicate)';
        }

        return [$w, $children, $errors, json_encode($lines)];
    },
];

echo "Transfer-priority race on mysql://{$host}/{$actualDb} — {$rounds} round(s) per scenario\n";
$before = $lockMetrics();
$failures = 0;
$total = 0;
foreach ($scenarios as $name => $scenario) {
    for ($round = 1; $round <= $rounds; $round++) {
        $total++;
        [$w, $children, $errors, $summary] = $scenario($round);
        $errors = array_merge($errors, $stockInvariants($w));
        $timing = implode(' | ', array_map(fn ($c) => ($c['ok'] ? ($c['result'] ?? 'ok') : class_basename($c['class'])).' '.$c['ms'].'ms', $children));
        printf("  %-17s #%-2d %s  %s  [%s]\n", $name, $round, $errors === [] ? 'PASS' : 'FAIL', $summary, $timing);
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

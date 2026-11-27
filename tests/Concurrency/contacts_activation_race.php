<?php

/**
 * MySQL concurrency regression for Unified Contacts (Phase 1): commercial profile / role activation.
 *
 * Real ManagePartyRolesAction runs in separate PHP processes (separate MySQL sessions):
 *
 *   php tests/Concurrency/contacts_activation_race.php [--sessions=6] [--rounds=2] [--database=commerce_erp_dev_test]
 *
 * Scenario A - "same party": N sessions add the Client AND Fournisseur roles to the SAME party at ONE barrier.
 *   Pass = every session succeeds (no error / deadlock / lock timeout), exactly ONE customer and ONE supplier carry the
 *   party_id, exactly one active party_role per role, exactly one 'party.customer_profile_created' and one
 *   'party.supplier_profile_created' audit row.
 * Scenario B - "same custom role": N sessions each add a differently-cased / accented spelling of the SAME new custom
 *   role to N distinct parties. Pass = exactly ONE contact_roles row, every session obtained that same role id,
 *   one 'contact_role.created' audit row, every party holds the role once.
 *
 * Scenario C - "same custom payment term": N sessions create the same custom 45-day term. Pass = exactly ONE catalogue row
 *   (besides the five standard terms) and every session obtained the same id.
 *
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases only;
 * every round creates its own organization. Requires a migrated database.
 */

use App\Actions\Contacts\ManagePartyRolesAction;
use App\Actions\Contacts\ManagePaymentTermsAction;
use App\Actions\Contacts\SavePartyAction;
use App\Enums\ContactSystemRole;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['sessions::', 'rounds::', 'database::', 'child::', 'org::', 'user::', 'party::', 'name::', 'start::']);
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
    try {
        $org = Organization::query()->findOrFail((int) $options['org']);
        $user = User::query()->findOrFail((int) $options['user']);
        $party = Party::query()->findOrFail((int) $options['party']);
        while (microtime(true) < (float) $options['start']) {
            usleep(100);
        }
        if ($options['child'] === 'term') {
            $term = app(ManagePaymentTermsAction::class)->create($user, $org, 45);
            echo json_encode(['ok' => true, 'role_ids' => [$term->id]]);
            exit(0);
        }
        $roles = $options['child'] === 'profiles' ? [ContactSystemRole::Client, ContactSystemRole::Supplier] : [(string) $options['name']];
        $applied = app(ManagePartyRolesAction::class)->add($user, $org, $party, $roles);
        echo json_encode(['ok' => true, 'role_ids' => array_map(fn ($r) => $r->id, $applied)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage(), 300)]);
    }
    exit(0);
}

// ───────────────────────────────────────────────────────────── parent
$sessions = max(2, (int) ($options['sessions'] ?? 6));
$rounds = max(1, (int) ($options['rounds'] ?? 2));
$errors = [];

$world = function (string $label): array {
    $owner = new User;
    $owner->name = "Contacts race {$label}";
    $owner->email = 'contacts-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Contacts race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();

    return [$owner, $org];
};

$newParty = fn (User $owner, Organization $org, string $name): Party => app(SavePartyAction::class)->execute($owner, $org, ['kind' => 'organization', 'display_name' => $name]);

$run = function (string $kind, array $jobs) use ($database): array {
    $start = microtime(true) + 1.5 + count($jobs) * 0.25;
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as $job) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$kind, '--org='.$job['org'], '--user='.$job['user'], '--party='.$job['party'], '--start='.$start, '--database='.$database];
        if (isset($job['name'])) {
            $cmd[] = '--name='.$job['name'];
        }
        $errFile = tempnam(sys_get_temp_dir(), 'car');
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
$before = $deadlocks();

for ($round = 1; $round <= $rounds; $round++) {
    // ---- Scenario A: same party, profiles activated concurrently
    [$owner, $org] = $world("A{$round}");
    $party = $newParty($owner, $org, "Dual {$round}");
    $results = $run('profiles', array_fill(0, $sessions, ['org' => $org->id, 'user' => $owner->id, 'party' => $party->id]));
    foreach ($results as $r) {
        if (! ($r['ok'] ?? false)) {
            $errors[] = "A{$round}: session failed (error / deadlock / timeout): ".json_encode($r);
        }
    }
    $count = fn (string $table) => DB::table($table)->where('organization_id', $org->id)->where('party_id', $party->id)->count();
    foreach (['customers' => 1, 'suppliers' => 1, 'party_roles' => 2] as $table => $expected) {
        if (($n = $count($table)) !== $expected) {
            $errors[] = "A{$round}: {$n} rows in {$table} for the party, expected {$expected}";
        }
    }
    foreach (['party.customer_profile_created', 'party.supplier_profile_created'] as $event) {
        $n = DB::table('audit_logs')->where('organization_id', $org->id)->where('event', $event)->count();
        if ($n !== 1) {
            $errors[] = "A{$round}: {$n} '{$event}' audit rows, expected 1";
        }
    }

    // ---- Scenario B: same custom role, different spellings, distinct parties
    [$owner, $org] = $world("B{$round}");
    $spellings = ['Électricien Agréé', 'electricien agree', 'ELECTRICIEN  AGREE', ' Électricien agréé '];
    $jobs = [];
    for ($i = 0; $i < $sessions; $i++) {
        $jobs[] = ['org' => $org->id, 'user' => $owner->id, 'party' => $newParty($owner, $org, "Person {$round}-{$i}")->id, 'name' => $spellings[$i % count($spellings)]];
    }
    $results = $run('custom', $jobs);
    $ids = [];
    foreach ($results as $r) {
        if (! ($r['ok'] ?? false)) {
            $errors[] = "B{$round}: session failed (error / deadlock / timeout): ".json_encode($r);
        } else {
            $ids[] = $r['role_ids'][0];
        }
    }
    if (count(array_unique($ids)) !== 1) {
        $errors[] = "B{$round}: sessions obtained different role ids: ".json_encode($ids);
    }
    if (($n = DB::table('contact_roles')->where('organization_id', $org->id)->count()) !== 1) {
        $errors[] = "B{$round}: {$n} custom roles persisted, expected 1";
    }
    if (($n = DB::table('audit_logs')->where('organization_id', $org->id)->where('event', 'contact_role.created')->count()) !== 1) {
        $errors[] = "B{$round}: {$n} 'contact_role.created' audit rows, expected 1";
    }
    if (($n = DB::table('party_roles')->where('organization_id', $org->id)->where('status', 'active')->count()) !== $sessions) {
        $errors[] = "B{$round}: {$n} active party roles, expected {$sessions}";
    }
}

// ---- Scenario C: same custom payment term
[$owner, $org] = $world('C');
$party = $newParty($owner, $org, 'Term holder');
$results = $run('term', array_fill(0, $sessions, ['org' => $org->id, 'user' => $owner->id, 'party' => $party->id]));
$ids = [];
foreach ($results as $r) {
    if (! ($r['ok'] ?? false)) {
        $errors[] = 'C: session failed (error / deadlock / timeout): '.json_encode($r);
    } else {
        $ids[] = $r['role_ids'][0];
    }
}
if (count(array_unique($ids)) !== 1) {
    $errors[] = 'C: sessions obtained different term ids: '.json_encode($ids);
}
if (($n = DB::table('payment_terms')->where('organization_id', $org->id)->where('days', 45)->count()) !== 1) {
    $errors[] = "C: {$n} 45-day terms persisted, expected 1";
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $errors[] = "InnoDB reported {$delta} deadlock(s)";
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}
echo "OK: contact role / profile activation is race-safe ({$rounds} rounds x {$sessions} sessions).\n";
exit(0);

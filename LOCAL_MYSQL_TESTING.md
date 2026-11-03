# Local MySQL verification environment

SQLite (`phpunit.xml`) ignores VARCHAR lengths, decimal scales, JSON key
normalization and InnoDB row/gap locking. Before deploying, verify on the local
Laragon MySQL (8.4) — the same engine as the server. **Local only:** every
command below targets `127.0.0.1`; never point them at the deployment server.

## Databases (local, disposable)

| Database | Purpose | Wiped by |
|---|---|---|
| `commerce_erp_dev` | realistic seeded data for manual checks | `migrate:fresh` (manual) |
| `commerce_erp_dev_test` | PHPUnit + concurrency harness | every `RefreshDatabase` run |

```sql
CREATE DATABASE commerce_erp_dev      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE commerce_erp_dev_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The day-to-day `.env` (`DB_DATABASE=commerce_erp`) is not changed; the
commands override the database name for one process only. Always print the
target first:

```bash
DB_DATABASE=commerce_erp_dev php artisan tinker --execute='echo DB::connection()->getDatabaseName()."@".config("database.connections.mysql.host");'
```

## Seeded development data

```bash
DB_DATABASE=commerce_erp_dev php artisan migrate:fresh
DB_DATABASE=commerce_erp_dev php artisan db:seed --class="Database\Seeders\Development\LocalVerificationSeeder"
```

`LocalVerificationSeeder` (never called by `DatabaseSeeder`) refuses non-local
hosts / non-local environments and an already-seeded database. It builds,
through the canonical Actions: organization, owner / admin / sales users
(`owner@erp.local`, `admin@erp.local`, `vendeuse@erp.local`, password
`password`), showroom + central depot, products and variants with varied stock,
showroom minimums, confirmed customer orders with reservations, a general
replenishment request, the Shure BETA 58A customer-linked request, and a
quotation → supplier → purchase order → partial receipt flow. It asserts the
Shure outcome (2 customer + 2 replenishment, 4 from the depot, no duplicate)
and aborts if the business logic disagrees.

## Test suites on MySQL

```bash
php -d memory_limit=2G vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Inventory
php -d memory_limit=2G vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Sales
# … Pos, Procurement, Fulfillment, Returns, Exchanges — one group at a time
```

`phpunit.mysql.xml` force-pins `127.0.0.1` / `commerce_erp_dev_test`. Run one
MySQL process at a time against that database.

## Concurrency regression (real parallel sessions)

```bash
php tests/Concurrency/transfer_priority_race.php --rounds=10
```

Spawns separate PHP processes released at the same instant (customer
confirmation vs minimum sweep, two orders competing for the same stock, three
simultaneous sweeps) and checks: customer priority, no duplicated or
over-promised stock, balances = active reservations, no deadlocks or lock-wait
timeouts (InnoDB metrics). Exit code 0 = pass. It only accepts local
`*_test` / `*_dev` databases and creates its own organizations per round. Do
not run it while PHPUnit is using the same database.

```bash
php tests/Concurrency/number_sequence_race.php --sessions=6 [--only=SalesOrder,Invoice]
```

Races every document-number generator (sales orders, quotations, invoices,
credit notes, delivery notes, payments, refunds, returns, exchanges,
procurements, purchase orders, stock transfers, transfer requests, the five
finance sequences) plus the inventory-balance row lock: N sessions allocate at
the same instant, on first use (row missing) and with an existing row. Pass =
no deadlock, unique and consecutive numbers (for balances: no lost update).
Both scripts accept `--database=<local *_test|*_dev db>`; a separate migrated
database (e.g. `commerce_erp_race_test`) lets them run while PHPUnit uses
`commerce_erp_dev_test`.

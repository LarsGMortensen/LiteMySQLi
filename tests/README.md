---

# LiteMySQLi – Integration Test

This repository contains a single, self-contained **integration test** that exercises the public surface of **LiteMySQLi** end-to-end against a real MySQL/MariaDB instance.

The test script verifies:

* Connection and basic SQL execution
* `fetchValue()`, `fetchRow()`, `fetchAll()`, `countRows()`
* `insert()`, `insertBatch()` (including chunked fallback), `update()`, `delete()`, `exists()`
* `select()` (buffered) and `selectNoMysqlnd()` (generator/streaming)
* `executeMany()` (single prepared SQL across many parameter sets)
* `queryRaw()` and `queryRawMulti()` (multi statement)
* Transactions: `beginTransaction()`, `commit()`, `rollback()`, and `easyTransaction()` with auto rollback on exception
* Instrumentation: `affectedRows()`, `lastInsertId()`, `countQueries(true)`
* Statement cache: limit, FIFO eviction, re-enable, and `clearStatementCache()`
* Error surfaces: last error message/code after failure, invalid identifiers throwing `InvalidArgumentException`
* DDL via `queryRaw()` (ALTER TABLE ADD INDEX / ADD COLUMN)
* Large payloads (`MEDIUMTEXT`, UTF-8/utf8mb4 with emojis) round-trip
* Lock wait/transactions using two connections (timeout, rollback correctness)
* Final cleanup (table drop)

If everything passes, you will see:

```
ALL TESTS PASSED ✅
```

---

## Requirements

* **PHP** 8.2+ with the `mysqli` extension enabled
* **MySQL** 8.x or **MariaDB** (InnoDB engine available)
* A database to run tests in (default: `test`)
* Credentials with permissions to: `CREATE TABLE`, `ALTER TABLE`, `INSERT`, `UPDATE`, `DELETE`, `SELECT`, `DROP TABLE`

> The script is non-destructive outside of its **own temporary table** (it creates and drops a uniquely named table like `ut_litemysqli_<random>`). No other schema objects are touched.

---

## Getting Started

1. **Clone** your project and ensure `LiteMySQLi.php` is available:

* If installed via Composer:

```php
// In LiteMySQLiTest.php
require_once __DIR__ . '/vendor/autoload.php';
```

* If testing the class file directly:

```php
// In LiteMySQLiTest.php
require_once __DIR__ . '/../src/LiteMySQLi.php';
```

2. **Configure database connection** via environment variables (recommended):

```bash
# Example (Linux/macOS)
export DB_HOST=127.0.0.1
export DB_USER=root
export DB_PASS=
export DB_NAME=test
```

On Windows (PowerShell):

```powershell
$env:DB_HOST="127.0.0.1"
$env:DB_USER="root"
$env:DB_PASS=""
$env:DB_NAME="test"
```

> If unset, the script falls back to:
>
> * `DB_HOST=localhost`
> * `DB_USER=root`
> * `DB_PASS=` (empty)
> * `DB_NAME=test`

3. **Run the test:**

```bash
php LiteMySQLiTest.php
```

---

## What the Script Does (Step by Step)

1. **Connection & Table Bootstrap**

   * Connects using `LiteMySQLi($host, $user, $pass, $db)`.
   * Creates a **unique temporary table** with representative types and constraints.
   * Ensures a clean slate with a best-effort `DROP TABLE IF EXISTS`.

2. **Basic CRUD / Reads**

   * Single `insert()` with `lastInsertId()` verification.
   * `fetchValue()`, `fetchRow()`, `fetchAll()` sanity checks.
   * `exists()` predicate.

3. **Updates & Deletes**

   * `update()` + `affectedRows()` checks.
   * `delete()` with positive and zero-affected outcomes.

4. **Batch Operations**

   * `insertBatch()` with mixed NULL/non-NULL fields.
   * `executeMany()` (reusing a prepared `UPDATE` for multiple param sets).

5. **Raw Queries**

   * `queryRaw()` for direct, single statements.
   * `queryRawMulti()` to exercise multi-statement execution and error handling midway.

6. **Transactions**

   * Manual `beginTransaction()` → `commit()` and `rollback()`.
   * `easyTransaction()` happy path and auto-rollback on exception.

7. **Instrumentation**

   * `countQueries(true)` (reset) + post-query delta.

8. **Statement Cache**

   * Set cache limit, prime cache with distinct SQLs, verify FIFO eviction order.
   * `clearStatementCache()`, disable cache (limit=0), and re-enable behavior.

9. **Extra Validations**

   * Invalid identifiers → `InvalidArgumentException`.
   * Post-error state: `getLastError()` and `getLastErrorCode()` non-zero.
   * Empty result handling for `fetchValue()` and `countRows()`.

10. **Character Set & Large Payloads**

    * `utf8mb4` round-trip of Danish characters and emoji.
    * `MEDIUMTEXT` inserts (single and batch) to test larger payloads.

11. **DDL and Index**

    * `ALTER TABLE ADD INDEX` and `ADD COLUMN` via `queryRaw()`.

12. **Lock/Concurrency Scenario**

    * Two separate `LiteMySQLi` connections.
    * Lowered `innodb_lock_wait_timeout` for fast feedback.
    * Lock contention, timeout, exception, and final state correctness.

13. **Cleanup**

    * Drops the temporary table.
    * Closes connections.

---

## Example Output (Truncated)

```
Connecting to MySQL…
Creating table ut_litemysqli_7fa1c9e3…
Testing insert()…
Testing update()…
Testing insertBatch()…
Testing select()…
Testing selectNoMysqlnd() streaming…
Testing executeMany()…
Testing queryRaw()…
Testing queryRawMulti()…
Testing transactions (manual)…
Testing transactions (rollback)…
Testing easyTransaction()…
Testing countQueries(reset)…
Testing statement cache behavior…
Extra: testing getLastError()/getLastErrorCode on failure…
Extra: testing invalid identifiers throw InvalidArgumentException…
Extra: testing schema-qualified table path in exists()…
Extra: testing fetchValue() and countRows() on empty set…
Extra: testing selectNoMysqlnd() early-break cleanup…
Extra: testing easyTransaction() rollback on exception…
Extra: testing queryRawMulti() failure mid-batch…
Extra: testing delete() no-op returns 0…
Extra: testing insertBatch() chunked fallback (>1000 rows)…
Reflection: testing internal statement cache…
Reflection: enabling cache with limit=2…
Extra++: DDL via queryRaw() (ADD INDEX / ADD COLUMN)…
Extra++: update() with no matching rows returns 0…
Extra++: executeMany() with no matches returns 0…
Extra++: large payload insert/insertBatch (MEDIUMTEXT)…
Extra++: charset sanity (æøå + emoji)…
Extra++: lock wait timeout / rollback with two connections…
Dropping table ut_litemysqli_7fa1c9e3…

ALL TESTS PASSED ✅
```

---

## Troubleshooting

* **Cannot connect**
  Verify host, port, user, password, and that the server accepts TCP connections from your machine.
  Try `DB_HOST=127.0.0.1` instead of `localhost` to avoid socket vs TCP differences.

* **Permissions error (CREATE/ALTER/DROP)**
  Ensure the database user has DDL rights for the target schema. Use a dedicated `test` schema if needed.

* **Character set issues**
  The table is created with `utf8mb4`. If your server defaults differ, ensure `collation_server` and client settings allow `utf8mb4` and full Unicode.

* **Lock/timeout test failing**
  Some managed environments restrict `SET SESSION innodb_lock_wait_timeout`. If the setting cannot be applied, the lock test may take longer or behave differently.

---

## Extending the Test

* Add more DDL permutations (unique keys, foreign keys, cascading rules).
* Benchmark helpers (e.g., measure wall-time for individual scenarios).
* Add coverage for edge cases you care about (e.g., very long identifiers—within MySQL limits—, zero-length strings vs NULL semantics).
* Add read-only transaction checks (`SET TRANSACTION READ ONLY`) if relevant.

> Keep tests **idempotent** and **self-cleaning**: create uniquely named resources and drop them during teardown.

---

## CI Usage

You can run this test in CI (GitHub Actions, GitLab CI, etc.) by:

1. Bringing up a MySQL service container (e.g., `mysql:8`) with a known root password.
2. Creating a `test` database.
3. Running `php LiteMySQLiTest.php` after setting `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME`.

Minimal GitHub Actions snippet:

```yaml
name: LiteMySQLi Integration Test

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=20
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mysqli
      - name: Wait for MySQL
        run: |
          for i in {1..60}; do
            mysqladmin ping -h127.0.0.1 -proot && break
            sleep 1
          done
      - name: Run integration test
        env:
          DB_HOST: 127.0.0.1
          DB_USER: root
          DB_PASS: root
          DB_NAME: test
        run: php LiteMySQLiTest.php
```

---

## Notes & Guarantees

* The script **throws** on any failed assertion (via small local assert helpers).
* It intentionally **does not** swallow exceptions; errors bubble up to PHP’s global error handler.
* All actions are performed within a single run and cleaned up at the end.

---

## License

This test script is covered by the same license as LiteMySQLi (e.g., GPL-3.0-or-later), unless explicitly stated otherwise in your repository.

---

## Acknowledgements

* Built to validate **LiteMySQLi**’s public API on real servers—not just mocks.
* Designed for fast local runs and easy CI integration.

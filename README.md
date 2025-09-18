# LiteMySQLi - Lightweight MySQLi Wrapper for PHP
LiteMySQLi is a **lightweight, high-performance MySQLi wrapper** for PHP 8.2+, designed to streamline database interactions with prepared statements, caching, transactions, and bulk operations.  

Unlike heavy ORMs, LiteMySQLi focuses on **speed, low overhead, and simplicity**, while still offering a clean and safe API for most common SQL operations.

## ✨ Features
- ✅ Minimalistic, intuitive API (single-file class)
- ✅ Secure **prepared statements** with automatic type binding
- ✅ **Statement caching** (FIFO, configurable limit, default: 128)
- ✅ **Scalar helpers** (`fetchValue()`, `fetchRow()`, `fetchAll()`)
- ✅ **Bulk operations** with `insertBatch()` and `executeMany()`
- ✅ **Transaction support** (`beginTransaction()`, `commit()`, `rollback()`, `easyTransaction()`)
- ✅ **Streaming SELECT without mysqlnd** (`selectNoMysqlnd()`)
- ✅ **Raw SQL execution** (`queryRaw()`, `queryRawMulti()`)
- ✅ Lightweight **profiling** (`countQueries()`)
- ✅ Deterministic cleanup of statements & connection (`close()`, `__destruct()`)

---

## ⚡ Installation
Simply include the `LiteMySQLi.php` file in your project:

```php
require_once 'LiteMySQLi.php';
````

---

## 🚀 Usage Examples

### Connect to the database

```php
$db = new LiteMySQLi('localhost', 'username', 'password', 'database');
```

### Fetch a single scalar value

```php
// Stewie checks if his world domination plan is ready
$plan = $db->fetchValue("SELECT status FROM evil_plans WHERE mastermind = ?", ['Stewie']);

if ($plan !== 'complete') {
    echo "Blast! Foiled again by that insufferable dog, Brian!";
}
```

### Fetch one row

```php
$cup = $db->fetchRow("SELECT * FROM coffee WHERE status = ? LIMIT 1", ['hot']); 
// probably null
```

### Fetch all rows

```php
$adventures = $db->fetchAll("SELECT * FROM adventures WHERE duo = ?", ['Bill and Ted']);
foreach ($adventures as $adventure) {
    echo "Adventure #" . $adventure['id'] . ": " . $adventure['title'] . " - most excellent!\n";
}
```

### Streaming fetch without mysqlnd

```php
foreach ($db->selectNoMysqlnd(
    "SELECT * FROM naps WHERE status = ?", ['interrupted']
) as $row) {
    echo $row['time'] . " - another debug at 3am\n";
}
```

### Insert a single row

```php
$id = $db->insert('users', [
    'name'  => 'Donald Duck',
    'email' => 'donald@disney.com'
]);
```

### Bulk insert

```php
// Add the Griffin family in one go
$db->insertBatch('griffins', [
    ['name' => 'Peter',   'role' => 'Dad',     'hobby' => 'Drunken antics'],
    ['name' => 'Lois',    'role' => 'Mom',     'hobby' => 'Piano and sarcasm'],
    ['name' => 'Meg',     'role' => 'Daughter','hobby' => 'Being ignored'],
    ['name' => 'Chris',   'role' => 'Son',     'hobby' => 'Drawing weird stuff'],
    ['name' => 'Stewie',  'role' => 'Baby',    'hobby' => 'World domination'],
    ['name' => 'Brian',   'role' => 'Dog',     'hobby' => 'Martinis and novels'],
]);
```

### Update rows

```php
$db->update('employees', ['status' => 'fired'], 'name = ?', ['Homer Simpson']);
// doh!
```

### Delete rows

```php
$db->delete('Canton', 'name = ?', ['Ryan Leaf']);
```

### Check if a record exists

```php
if ($db->exists('users', 'email = ?', ['neo@matrix.io'])) {
    echo "Whoa. He’s already in the system.";
}
```

### Count rows

```php
$walkers = $db->countRows("SELECT * FROM zombies WHERE location = ?", ['Alexandria']);
```

### Transactions

```php
$db->beginTransaction();
try {
    $db->insert('Cheers', ['name' => 'Sam Malone']);
    $db->insert('Cheers', ['name' => 'Diane Chambers']);
    $db->insert('Cheers', ['name' => 'Norm Peterson']);
    $db->commit(); // where everybody knows your name
} catch (Throwable $e) {
    $db->rollback(); // back to drinking alone
}
```

### Easy transaction (auto rollback on error)

```php
// Successful transaction (committed)
$db->easyTransaction(function($db) {
    // Insert player (parent)
    $db->insert('players', ['name' => 'Tom Brady']);

    // Child row references the just-created player via lastInsertId()
    $db->insert('comebacks', ['player_id' => $db->lastInsertId(), 'score' => '28-3']);
});

// Failing transaction (rolled back automatically)
try {
    $db->easyTransaction(function($db) {
        // Looks great at halftime...
        $db->insert('teams', ['name' => 'Atlanta Falcons', 'lead' => '28-3']);

        // ...but something goes wrong — trigger rollback of everything above
        throw new RuntimeException("Celebrating too early... rollback!");
    });
} catch (\Throwable $e) {
    // Already rolled back; no rows from this block were persisted
}

```

### Raw SQL execution

```php
$db->queryRaw("CREATE TEMPORARY TABLE time_travelers (id INT, name VARCHAR(50))");
$db->queryRaw("INSERT INTO time_travelers VALUES (1, 'Marty'), (2, 'Doc Brown')");

$res = $db->queryRaw("SELECT * FROM time_travelers");
while ($row = $res->fetch_assoc()) {
    echo $row['id'] . ': ' . $row['name'] . PHP_EOL;
}
$res->free();
```

### Multiple raw SQL statements

```php
$results = $db->queryRawMulti("
    CREATE TEMPORARY TABLE gallaghers (id INT, name VARCHAR(50));
    INSERT INTO gallaghers (id, name) VALUES (1, 'Frank'), (2, 'Lip'), (3, 'Fiona');
    SELECT * FROM gallaghers;
");

foreach ($results as $res) {
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            echo $row['id'] . ' -> ' . $row['name'] . PHP_EOL;
        }
        $res->free();
    }
}
```

---

## 🔧 Utility Methods

* `lastInsertId()` -> returns the last AUTO\_INCREMENT value
* `affectedRows()` -> number of rows affected by last query
* `countQueries($reset = false)` -> number of executed queries (optionally reset)
* `getLastError() / getLastErrorCode()` -> retrieve last MySQL error
* `setStatementCacheLimit($limit)` -> change cache size or disable caching
* `clearStatementCache()` -> free all cached prepared statements
* `close()` -> explicit cleanup (statements + connection)

---

## ⚠️ Error Handling

LiteMySQLi defaults to **strict error mode** (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`),  
which means **all database errors throw `mysqli_sql_exception` immediately**:

```php
try {
    $db->execute("INVALID SQL");
} catch (mysqli_sql_exception $e) {
    echo "Database error: " . $e->getMessage();
}
````

### Soft error inspection

If you prefer to **inspect errors manually** (e.g. for logging or batch scripts),
you can use the helper methods:

```php
$db->execute("INVALID SQL");

if ($db->getLastErrorCode() !== 0) {
    echo "Error code: " . $db->getLastErrorCode();
    echo "Error message: " . $db->getLastError();
}
```

* `getLastErrorCode()` → last error code (0 if none).
* `getLastError()` → last error message (null if none).

These methods do **not** throw and can be used alongside or instead of exceptions.

---

## 💡 Why LiteMySQLi?

- ⚡ **Blazing fast by design** - minimal overhead, tiny call graph, and OPcache-friendly code paths.
- 🔒 **Safety first** - prepared statements everywhere, type-aware binding, no string concatenation footguns.
- 💾 **Memory-lean** - predictable allocations, no ORM hydration bloat or proxy objects.
- 🧩 **Zero dependencies** - a single ~60 KB PHP file; nothing else to install or maintain.
- 🚀 **Shared-hosting friendly** - runs anywhere PHP + MySQLi runs (perfect for budget and legacy hosts).
- ♻️ **Smart statement cache** - FIFO cache reuses prepared statements for repeat queries to cut latency.
- 📦 **Bulk at scale** - `insertBatch()` and `executeMany()` deliver high throughput for large datasets.
- 🧵 **Deterministic cleanup** - `close()` and a safe `__destruct()` ensure resources are freed promptly.
- 🧪 **Strict error mode** - leverages `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` to fail fast and loud.
- 🔍 **Lightweight profiling** - `countQueries()` gives you instant per-request query counts.
- 📈 **Index awareness** - optional `MYSQLI_REPORT_INDEX` helps surface “missing/bad index” warnings early.
- 🌊 **Streams without mysqlnd** - `selectNoMysqlnd()` reads large result sets with tiny memory footprints.
- 🧰 **Pragmatic escape hatches** - `queryRaw()` and `queryRawMulti()` for migrations and admin tasks.
- 🛡️ **Transaction-strong** - explicit `begin/commit/rollback` + `easyTransaction()` convenience wrapper.
- 🧭 **No hidden magic** - what you write is what runs; SQL remains front and center.
- 🧰 **Drop-in adoption** - keep your existing SQL and migrate one query at a time with near-zero risk.
- 🔁 **Idempotent helpers** - `fetchValue()`, `fetchRow()`, `fetchAll()` cover 90% of day-to-day reads.
- 🧱 **Strict identifier quoting** - safe table/column quoting via `quoteIdentifier*()` utilities.
- 🛠 **Operations-ready** - explicit resource freeing, predictable failure modes, and clean connection state.
- 🧩 **Framework-agnostic** - fits neatly into any codebase; PSR-4 compatible, no global state.
- 🧭 **MariaDB/MySQL compatible** - targets mainstream MySQL/MariaDB features, not vendor-specific quirks.
- ⏱ **Benchmark-friendly** - simple API surface makes it easy to measure and tune hot paths.
- 🧾 **GPL-3.0-or-later** - open source with a clear, permissive-for-FOSS licensing model.
- 🧑‍💻 **Built for PHP 8.2+** - typed properties, strict types, modern language features by default.
- 🔧 **Config on your terms** - adjustable statement cache size, strictness, and charset.

> **TL;DR:** LiteMySQLi gives you raw SQL speed, modern safety, and production-grade ergonomics - without the ORM baggage.


Perfect for developers who want **raw SQL power** with **modern safety and speed**.

---

## 📜 License

LiteMySQLi is released under the **GNU General Public License v3.0 or later**.
See [LICENSE](LICENSE) for details.

---

## 👨‍💻 Author

Developed by **Lars Grove Mortensen** © 2025
Contributions and pull requests are welcome!

---

🌟 **If you find this library useful, give it a star on GitHub!** 🌟

```

---
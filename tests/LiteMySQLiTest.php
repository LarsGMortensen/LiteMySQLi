<?php
/**
 * LiteMySQLi – Integration Test Script
 *
 * This script exercises the public surface of LiteMySQLi:
 * - Connection, basic execute/select
 * - fetchValue / fetchRow / fetchAll / countRows
 * - insert / insertBatch / update / delete / exists
 * - executeMany (batch using a single prepared SQL)
 * - queryRaw / queryRawMulti
 * - beginTransaction / commit / rollback / easyTransaction
 * - affectedRows / lastInsertId / countQueries(reset)
 * - Statement cache behavior (limit / eviction / clear)
 *
 * It creates a unique temporary table, runs tests, and drops it.
 *
 * Run:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= DB_NAME=test php LiteMySQLiTest.php
 */

declare(strict_types=1);

use LiteMySQLi\LiteMySQLi;

// ---- Adjust path if needed (composer autoload or direct include) ----
// require_once __DIR__ . '/vendor/autoload.php';
// If you are testing the class file directly (no composer):
require_once __DIR__ . '/../src/LiteMySQLi.php';

// ---- Config (env with safe defaults) ----
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_NAME = getenv('DB_NAME') ?: 'test';

// ---- Tiny assertion helpers (throw on failure) ----
/**
 * @throws RuntimeException
 */
function assertTrue(bool $cond, string $msg = 'assertTrue failed'): void {
	if (!$cond) throw new RuntimeException($msg);
}
/**
 * @param mixed $a
 * @param mixed $b
 * @throws RuntimeException
 */
function assertSame($a, $b, string $msg = 'assertSame failed'): void {
	if ($a !== $b) {
		throw new RuntimeException($msg . " | Expected: " . var_export($b, true) . " Got: " . var_export($a, true));
	}
}
/**
 * @param mixed $a
 * @param mixed $b
 * @throws RuntimeException
 */
function assertEquals($a, $b, string $msg = 'assertEquals failed'): void {
	if ($a != $b) {
		throw new RuntimeException($msg . " | Expected: " . var_export($b, true) . " Got: " . var_export($a, true));
	}
}

echo "Connecting to MySQL…\n";
$db = new LiteMySQLi($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// Unique temp table
$tbl = 'ut_litemysqli_' . bin2hex(random_bytes(4));

// Clean slate (ignore errors)
@$db->queryRaw("DROP TABLE IF EXISTS `{$tbl}`");

// Create table
echo "Creating table {$tbl}…\n";
$db->queryRaw("
	CREATE TABLE `{$tbl}` (
		id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		email VARCHAR(190) NOT NULL UNIQUE,
		name VARCHAR(100) NULL,
		active TINYINT(1) NOT NULL DEFAULT 1,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Basic insert()
echo "Testing insert()…\n";
$id1 = $db->insert($tbl, ['email' => 'a@example.com', 'name' => 'Alice', 'active' => 1]);
assertTrue($id1 > 0, 'insert() should return auto id');
assertSame($id1, $db->lastInsertId(), 'lastInsertId should reflect last insert');

// fetchValue()
$cnt = $db->fetchValue("SELECT COUNT(*) FROM `{$tbl}`");
assertSame(1, (int)$cnt, 'fetchValue COUNT after first insert');

// fetchRow()
$row = $db->fetchRow("SELECT id,email,name,active FROM `{$tbl}` WHERE id = ?", [$id1]);
assertSame('a@example.com', $row['email']);
assertSame('Alice', $row['name']);
assertSame(1, (int)$row['active']);

// exists()
assertTrue($db->exists($tbl, 'email = ?', ['a@example.com']), 'exists() should find row');

// update()
echo "Testing update()…\n";
$affected = $db->update($tbl, ['name' => 'Alicia', 'active' => 0], 'id = ?', [$id1]);
assertTrue($affected >= 0, 'update affected rows should be >= 0');
assertSame($affected, $db->affectedRows(), 'affectedRows reflects last UPDATE');
$row = $db->fetchRow("SELECT name,active FROM `{$tbl}` WHERE id = ?", [$id1]);
assertSame('Alicia', $row['name']);
assertSame(0, (int)$row['active']);

// insertBatch()
echo "Testing insertBatch()…\n";
$batch = [];
for ($i = 0; $i < 5; $i++) {
	$batch[] = [
		'email'  => "user{$i}@example.com",
		'name'   => ($i % 2 === 0 ? null : "User {$i}"), // also tests NULL handling
		'active' => ($i % 2),
	];
}
$insRows = $db->insertBatch($tbl, $batch);
assertSame(5, $insRows, 'insertBatch should insert 5 rows');

// fetchAll()
$rows = $db->fetchAll("SELECT email, name, active FROM `{$tbl}` ORDER BY id ASC");
assertTrue(count($rows) >= 6, 'fetchAll should have at least 6 rows now');

// countRows() with SQL
$c = $db->countRows("SELECT * FROM `{$tbl}` WHERE active = ?", [1]);
assertTrue($c >= 2, 'countRows with SQL should count active rows');

// select() + free()
echo "Testing select()…\n";
$res = $db->select("SELECT id,email FROM `{$tbl}` WHERE email LIKE ?", ['%example.com']);
assertTrue($res->num_rows >= 6, 'select() result size check');
$res->free(); // important before reusing same cached SQL

// selectNoMysqlnd() streaming
echo "Testing selectNoMysqlnd() streaming…\n";
$streamCount = 0;
foreach ($db->selectNoMysqlnd("SELECT id,email,name,active FROM `{$tbl}` WHERE email LIKE ?", ['%example.com']) as $r) {
	$streamCount++;
}
assertTrue($streamCount >= 6, 'selectNoMysqlnd() should stream rows');

// executeMany() with single prepared SQL
echo "Testing executeMany()…\n";
$sqlMany = "UPDATE `{$tbl}` SET active = ? WHERE email = ?";
$paramSets = [
	[1, 'a@example.com'],
	[1, 'user0@example.com'],
	[1, 'user1@example.com'],
];
$upd = $db->executeMany($sqlMany, $paramSets);
assertTrue($upd >= 0, 'executeMany should return affected rows (>=0)');
$activeCount = (int)$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE active = 1");
assertTrue($activeCount >= 3, 'active rows should have increased');

// queryRaw() simple
echo "Testing queryRaw()…\n";
$db->queryRaw("UPDATE `{$tbl}` SET name = 'ZZZ' WHERE email = 'user0@example.com'");
$nm = $db->fetchValue("SELECT name FROM `{$tbl}` WHERE email = 'user0@example.com'");
assertSame('ZZZ', $nm, 'queryRaw UPDATE should apply');

// queryRawMulti()
echo "Testing queryRawMulti()…\n";
$multi = $db->queryRawMulti("
	INSERT INTO `{$tbl}` (email,name,active) VALUES ('multi1@example.com','M1',1);
	INSERT INTO `{$tbl}` (email,name,active) VALUES ('multi2@example.com','M2',0);
	SELECT COUNT(*) AS c FROM `{$tbl}`;
");
assertTrue(count($multi) === 3, 'multi_query should produce 3 results');
$last = $multi[2];
assertTrue($last instanceof mysqli_result, '3rd result should be a result set');
$multiCount = $last->fetch_assoc();
$last->free();
assertTrue((int)$multiCount['c'] >= 8, 'multi_query count sanity');

// Transactions: manual begin/commit
echo "Testing transactions (manual)…\n";
$db->beginTransaction();
$newId = $db->insert($tbl, ['email' => 'tx@example.com', 'name' => 'TX', 'active' => 1]);
assertTrue($newId > 0);
$db->commit();
assertTrue($db->exists($tbl, 'email = ?', ['tx@example.com']), 'commit should persist row');

// Transactions: rollback
echo "Testing transactions (rollback)…\n";
$db->beginTransaction();
$rid = $db->insert($tbl, ['email' => 'rollback@example.com', 'name' => 'RB', 'active' => 1]);
assertTrue($rid > 0);
$db->rollback();
assertTrue(!$db->exists($tbl, 'email = ?', ['rollback@example.com']), 'rollback should revert');

// easyTransaction()
echo "Testing easyTransaction()…\n";
$db->easyTransaction(function(LiteMySQLi $conn) use ($tbl): void {
	$conn->insert($tbl, ['email' => 'easy1@example.com', 'name' => 'E1', 'active' => 1]);
	$conn->insert($tbl, ['email' => 'easy2@example.com', 'name' => 'E2', 'active' => 0]);
});
assertTrue($db->exists($tbl, 'email = ?', ['easy1@example.com']));
assertTrue($db->exists($tbl, 'email = ?', ['easy2@example.com']));

// countQueries(reset) – sanity (we don’t assert a fixed number; just that it’s an int and reset works)
echo "Testing countQueries(reset)…\n";
$before = $db->countQueries(true);
assertTrue(is_int($before), 'countQueries returns int');
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}`");
$after = $db->countQueries();
assertSame(1, $after, 'countQueries after a single SELECT should be 1');

// Statement cache: limit / eviction / clear
echo "Testing statement cache behavior…\n";
$db->setStatementCacheLimit(3);
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE email = ?", ['a@example.com']); // S1
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE email = ?", ['user0@example.com']); // S2 (same SQL as S1 -> same cache key)
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE active = ?", [1]); // S3
$db->fetchValue("SELECT id FROM `{$tbl}` WHERE email = ?", ['a@example.com']); // S4 -> should evict oldest if keys differ
// No exception thrown == OK. Now clear:
$db->clearStatementCache();
$db->setStatementCacheLimit(0); // disable cache
// Do a query to ensure no crash w/o cache:
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE active = ?", [1]);

// delete()
echo "Testing delete()…\n";
$del = $db->delete($tbl, 'email IN (?,?)', ['multi1@example.com', 'multi2@example.com']);
assertTrue($del >= 0, 'delete should return affected rows');
assertTrue(!$db->exists($tbl, 'email = ?', ['multi1@example.com']));
assertTrue(!$db->exists($tbl, 'email = ?', ['multi2@example.com']));

// countRows() with mysqli_result
$res2 = $db->select("SELECT * FROM `{$tbl}` WHERE email LIKE ?", ['%example.com']);
$n2 = $db->countRows($res2);
assertTrue($n2 >= 1, 'countRows with mysqli_result should count rows');
$res2->free();


// -------------------------
// --- EXTRA TESTS START ---
// -------------------------

echo "Extra: testing getLastError()/getLastErrorCode on failure…\n";
try {
	$db->queryRaw("SELECT * FROM `definitely_non_existing_table_xyz`");
	assertTrue(false, "Expected mysqli_sql_exception for invalid table");
} catch (\mysqli_sql_exception $e) {
	// After exception, the connection holds last error
	assertTrue($db->getLastError() !== null, "getLastError should return a message after failure");
	assertTrue($db->getLastErrorCode() !== 0, "getLastErrorCode should be non-zero after failure");
}
// Ensure subsequent queries still work
assertSame(1, (int)$db->fetchValue("SELECT 1"));

echo "Extra: testing invalid identifiers throw InvalidArgumentException…\n";
// insert with invalid table name
try {
	$db->insert('bad-name', ['col' => 'x']);
	assertTrue(false, "Expected InvalidArgumentException for invalid table name");
} catch (\InvalidArgumentException $e) {}
// update with invalid column name
try {
	$db->update($tbl, ['bad-col' => 'x'], 'id = ?', [0]);
	assertTrue(false, "Expected InvalidArgumentException for invalid column name");
} catch (\InvalidArgumentException $e) {}
// delete with invalid table path segment
try {
	$db->delete('schema.with-bad-seg', 'id = ?', [0]);
	assertTrue(false, "Expected InvalidArgumentException for invalid path segment");
} catch (\InvalidArgumentException $e) {}

echo "Extra: testing schema-qualified table path in exists()…\n";
$qualified = $DB_NAME . '.' . $tbl;
assertTrue($db->exists($qualified, 'id = ?', [$id1]) === true, "exists should work with schema-qualified table");

// fetchValue() on empty result → null
echo "Extra: testing fetchValue() and countRows() on empty set…\n";
$val = $db->fetchValue("SELECT email FROM `{$tbl}` WHERE email = ?", ['nope@example.com']);
assertTrue($val === null, "fetchValue on empty should return null");
$resEmpty = $db->select("SELECT * FROM `{$tbl}` WHERE email = ?", ['nope@example.com']);
assertSame(0, $db->countRows($resEmpty), "countRows(mysqli_result) should be 0 for empty");
$resEmpty->free();

// selectNoMysqlnd(): early break should still close statement under the hood
echo "Extra: testing selectNoMysqlnd() early-break cleanup…\n";
$iter = $db->selectNoMysqlnd("SELECT id,email FROM `{$tbl}` ORDER BY id ASC");
$seen = 0;
foreach ($iter as $r) {
	$seen++;
	if ($seen >= 2) break;
}
unset($iter); 
// If statement didn't close, the next re-use on same connection/SQL could fail.
// Run a harmless query to ensure connection is fine:
assertSame(1, (int)$db->fetchValue("SELECT 1"), "Connection should remain usable after early-break");

// easyTransaction(): failing callback should rollback automatically
echo "Extra: testing easyTransaction() rollback on exception…\n";
try {
	$db->easyTransaction(function(\LiteMySQLi\LiteMySQLi $conn) use ($tbl) {
		$conn->insert($tbl, ['email' => 'must_rollback@example.com', 'name' => 'RB2', 'active' => 1]);
		throw new \RuntimeException("Force rollback");
	});
	assertTrue(false, "Expected exception from easyTransaction callback");
} catch (\RuntimeException $e) {
	// verify rollback happened
	assertTrue(!$db->exists($tbl, 'email = ?', ['must_rollback@example.com']), "Row must not persist after rollback");
}

// queryRawMulti(): error mid-batch should throw, connection remains usable
echo "Extra: testing queryRawMulti() failure mid-batch…\n";
try {
	$db->queryRawMulti("
		INSERT INTO `{$tbl}` (email,name,active) VALUES ('m_ok1@example.com','OK1',1);
		SELECT * FROM definitely_non_existing_table_xyz;  -- this will fail
		INSERT INTO `{$tbl}` (email,name,active) VALUES ('m_ok2@example.com','OK2',1);
	");
	assertTrue(false, "Expected mysqli_sql_exception from queryRawMulti");
} catch (\mysqli_sql_exception $e) {
	// connection should be back in sync; a simple query should still work
	assertSame(1, (int)$db->fetchValue("SELECT 1"), "Connection must remain usable after multi_query failure");
	// The successful first INSERT should still be there; the third should not have executed after failure.
	assertTrue($db->exists($tbl, 'email = ?', ['m_ok1@example.com']), "First INSERT before failing statement should have executed");
	assertTrue(!$db->exists($tbl, 'email = ?', ['m_ok2@example.com']), "Third INSERT after failing statement should not have executed");
}

// delete() returning 0 when no rows match
echo "Extra: testing delete() no-op returns 0…\n";
$delZero = $db->delete($tbl, 'email = ?', ['nonexistent@example.com']);
assertSame(0, $delZero, "delete with no matches should return 0");

// insertBatch(): force chunked fallback path by exceeding ROW_LIMIT (1000)
echo "Extra: testing insertBatch() chunked fallback (>1000 rows)…\n";
$big = [];
for ($i = 0; $i < 1100; $i++) {
	$big[] = [
		'email'  => "bulk{$i}_" . bin2hex(random_bytes(2)) . "@example.com",
		'name'   => null,         // also exercise NULL binding
		'active' => ($i % 2),     // bool/int mapping
	];
}
$beforeCount = (int)$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}`");
$added = $db->insertBatch($tbl, $big); // should use chunked path internally
assertSame(1100, $added, "insertBatch should report rows inserted (chunked path)");
$afterCount = (int)$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}`");
assertSame($beforeCount + 1100, $afterCount, "Row count should increase by 1100");

// -----------------------
// --- EXTRA TESTS END ---
// -----------------------



// -------------------------------
// --- REFLECTION CACHE TESTS ---
// -------------------------------

echo "Reflection: testing internal statement cache…\n";

/**
 * Get private property value via reflection.
 * @param object $obj
 * @param string $prop
 * @return mixed
 */
function reflectGet(object $obj, string $prop) {
	$rc = new \ReflectionClass($obj);
	$rp = $rc->getProperty($prop);
	$rp->setAccessible(true);
	return $rp->getValue($obj);
}

/**
 * Helper to fetch cache keys (SQL strings) preserving insertion order.
 * @param object $db
 * @return array
 */
function getCacheKeys(object $db): array {
	$cache = reflectGet($db, 'statementCache');
	return array_keys($cache);
}

// Start from a clean state: disable cache (also clears it)
$db->setStatementCacheLimit(0);
assertSame(0, count(reflectGet($db, 'statementCache')), "Cache should be empty after disabling");

echo "Reflection: enabling cache with limit=2…\n";
$db->setStatementCacheLimit(2);
assertSame(0, count(reflectGet($db, 'statementCache')), "Cache remains empty until a prepared query runs");

// Run two distinct prepared queries (→ 2 cache entries)
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE email = ?", ['a@example.com']); // SQL A
$keysAfterA = getCacheKeys($db);
assertSame(1, count($keysAfterA), "Cache should have 1 entry after first prepared");

$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE active = ?", [1]); // SQL B
$keysAfterB = getCacheKeys($db);
assertSame(2, count($keysAfterB), "Cache should have 2 entries after second prepared");

// Third distinct SQL should evict the oldest (FIFO)
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE name IS NOT NULL"); // SQL C (no params, still prepared)
$keysAfterC = getCacheKeys($db);
assertSame(2, count($keysAfterC), "Cache size should remain at limit (2) after eviction");

// Verify FIFO: first key from keysAfterB should be gone in keysAfterC
$evicted = array_diff($keysAfterB, $keysAfterC);
assertSame(1, count($evicted), "Exactly one SQL should be evicted");
$evictedSql = array_values($evicted)[0];
// Sanity: the evicted should equal the oldest (first) from keysAfterB
assertSame($keysAfterB[0], $evictedSql, "FIFO eviction should remove the oldest SQL");

// clearStatementCache should empty the cache
$db->clearStatementCache();
assertSame(0, count(reflectGet($db, 'statementCache')), "Cache should be empty after clearStatementCache");

// Disabling cache again should keep it empty even after queries
$db->setStatementCacheLimit(0);
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}`"); // prepared but not cached when limit=0
assertSame(0, count(reflectGet($db, 'statementCache')), "Cache should remain empty with limit=0");

// Re-enable and ensure fresh population works again
$db->setStatementCacheLimit(3);
$db->fetchValue("SELECT COUNT(*) FROM `{$tbl}` WHERE id > ?", [0]); // SQL D
assertSame(1, count(reflectGet($db, 'statementCache')), "Cache should repopulate after re-enable");





// -------------------------------------------------
// --- EXTRA++: DDL, zero-affected, payload, locks ---
// -------------------------------------------------

echo "Extra++: DDL via queryRaw() (ADD INDEX / ADD COLUMN)…\n";
// Add index (idempotency not required; table is temp per run)
$ddl1 = $db->queryRaw("ALTER TABLE `{$tbl}` ADD INDEX `idx_active` (`active`)");
assertTrue($ddl1 === true, "ALTER TABLE ADD INDEX should return true");

// Add a MEDIUMTEXT column for large payload tests
$ddl2 = $db->queryRaw("ALTER TABLE `{$tbl}` ADD COLUMN `big_txt` MEDIUMTEXT NULL");
assertTrue($ddl2 === true, "ALTER TABLE ADD COLUMN should return true");

// Zero-affected UPDATE
echo "Extra++: update() with no matching rows returns 0…\n";
$upd0 = $db->update($tbl, ['name' => 'NoHit'], 'id = ?', [-1]);
assertSame(0, $upd0, "UPDATE with no matches should return 0");

// Zero-affected executeMany
echo "Extra++: executeMany() with no matches returns 0…\n";
$sqlNoHit = "UPDATE `{$tbl}` SET name = ? WHERE id = ?";
$noHitParams = [
	['X1', -101],
	['X2', -102],
];
$updMany0 = $db->executeMany($sqlNoHit, $noHitParams);
assertSame(0, $updMany0, "executeMany with no matches should return 0");

// Large payloads in insert() and insertBatch()
echo "Extra++: large payload insert/insertBatch (MEDIUMTEXT)…\n";
$long1 = str_repeat('ÆØÅ🙂', 1000); // ~4000+ bytes utf8mb4
$idLong = $db->insert($tbl, [
	'email'   => 'long1@example.com',
	'name'    => 'Long One',
	'active'  => 1,
	'big_txt' => $long1
]);
assertTrue($idLong > 0, "insert with MEDIUMTEXT should succeed");
$round = $db->fetchRow("SELECT big_txt FROM `{$tbl}` WHERE id = ?", [$idLong]);
assertSame($long1, $round['big_txt'], "Round-trip MEDIUMTEXT content must match");

// Batch with large payloads (smaller batch to keep runtime safe)
$batchLong = [];
for ($i = 0; $i < 10; $i++) {
	$batchLong[] = [
		'email'   => "long_batch_{$i}@" . bin2hex(random_bytes(2)) . ".example.com",
		'name'    => null,
		'active'  => ($i % 2),
		'big_txt' => str_repeat('emoji🙂', 800) // a few KB each
	];
}
$addedLong = $db->insertBatch($tbl, $batchLong);
assertSame(10, $addedLong, "insertBatch with MEDIUMTEXT rows should insert all");

// Charset sanity (æøå + emoji)
echo "Extra++: charset sanity (æøå + emoji)…\n";
$txt = "Smørrebrød æøå 🤘🔥🐘";
$idCh = $db->insert($tbl, [
	'email'   => 'charset@example.com',
	'name'    => 'Charset',
	'active'  => 1,
	'big_txt' => $txt
]);
$got = $db->fetchValue("SELECT big_txt FROM `{$tbl}` WHERE id = ?", [$idCh]);
assertSame($txt, $got, "UTF-8 content (utf8mb4) must round-trip correctly");

// Deadlock / lock wait timeout simulation with two connections
echo "Extra++: lock wait timeout / rollback with two connections…\n";
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'test';

// Prepare a target row
$rowId = $db->insert($tbl, [
	'email'   => 'lock@test.example',
	'name'    => 'LockTarget',
	'active'  => 1,
	'big_txt' => 'initial'
]);

// Open a second connection
$connB = new \LiteMySQLi\LiteMySQLi($host, $user, $pass, $name);

// Reduce lock wait timeout for faster tests (per-connection)
$db->queryRaw("SET SESSION innodb_lock_wait_timeout = 1");
$connB->queryRaw("SET SESSION innodb_lock_wait_timeout = 1");

// A) Connection A locks the row (uncommitted update)
$db->beginTransaction();
$db->update($tbl, ['big_txt' => 'A-holds-lock'], 'id = ?', [$rowId]);

// B) Connection B tries to update same row inside easyTransaction() -> expect timeout => exception => rollback
$thrown = false;
try {
	$connB->easyTransaction(function(\LiteMySQLi\LiteMySQLi $c) use ($tbl, $rowId) {
		$c->update($tbl, ['big_txt' => 'B-write'], 'id = ?', [$rowId]);
	});
} catch (\Throwable $e) {
	$thrown = true;
}
assertTrue($thrown, "Lock wait timeout should throw inside easyTransaction and auto-rollback");

// Verify that connection B's change was not committed
$stillA = $db->fetchValue("SELECT big_txt FROM `{$tbl}` WHERE id = ?", [$rowId]);
assertSame('A-holds-lock', $stillA, "Row should still reflect A's uncommitted change");

// Release A's lock and ensure row is writable again
$db->rollback(); // discard A's change
$after = $db->fetchValue("SELECT big_txt FROM `{$tbl}` WHERE id = ?", [$rowId]);
assertSame('initial', $after, "Rollback must restore initial state");

// Now B can write successfully
$connB->beginTransaction();
$connB->update($tbl, ['big_txt' => 'B-final'], 'id = ?', [$rowId]);
$connB->commit();
$final = $db->fetchValue("SELECT big_txt FROM `{$tbl}` WHERE id = ?", [$rowId]);
assertSame('B-final', $final, "Row should reflect B's committed change after locks released");

// Clean up B
$connB->close();





// Final cleanup
echo "Dropping table {$tbl}…\n";
$db->queryRaw("DROP TABLE `{$tbl}`");

// If we get here, all assertions passed
echo "\nALL TESTS PASSED ✅\n";

// Optional explicit close (class also closes in destructor)
$db->close();

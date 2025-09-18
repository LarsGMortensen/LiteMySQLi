<?php
/**
 * LiteMySQLi - Lightweight MySQLi Database Wrapper for PHP
 * 
 * Copyright (C) 2025 Lars Grove Mortensen. All rights reserved.
 * 
 * LiteMySQLi is a simple, lightweight wrapper around PHP's MySQLi extension,
 * designed to streamline database interactions with prepared statements,
 * caching, and transaction support.
 * 
 * LiteMySQLi is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * LiteMySQLi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with LiteMySQLi. If not, see <https://www.gnu.org/licenses/>.
 */
 
declare(strict_types=1);

namespace LiteMySQLi;


final class LiteMySQLi {
	
	/**
	* Holds the active database connection.
	* 
	* @var \mysqli The MySQLi connection instance used for database interactions.
	*/
	private \mysqli $connection;
	

	/**
	 * In-memory cache of prepared statements, keyed by SQL string.
	 *
	 * This cache reduces overhead by reusing prepared \mysqli_stmt objects
	 * for queries that are executed repeatedly with different parameters.
	 * Each unique SQL string corresponds to one prepared statement.
	 *
	 * Behavior:
	 * - When a query is executed, the cache is checked first.
	 * - If the statement exists, it is reused directly (saving a prepare() call).
	 * - If not, a new statement is prepared and stored in the cache.
	 * - When the cache reaches {@see $statementCacheLimit}, the oldest
	 *   cached statement is evicted (FIFO) and closed to free resources.
	 *
	 * @var array<string,\mysqli_stmt>
	 */
	private array $statementCache = [];


	/**
	 * Maximum number of prepared statements to keep in the cache.
	 *
	 * Controls the size of the statement cache. A sensible default of 128
	 * balances performance and memory usage for most applications.
	 *
	 * Behavior:
	 * - Once the cache reaches this limit, the oldest statement is closed
	 *   and removed before inserting a new one.
	 *
	 * Tuning:
	 * - Increase for applications with a wide variety of prepared queries
	 *   that are reused frequently.
	 * - Decrease if memory is constrained or the workload involves only
	 *   a small, stable set of queries.
	 *
	 * @var int
	 */
	private int $statementCacheLimit = 128;


	/**
	 * Tracks the total number of SQL statements executed by this instance.
	 *
	 * The counter is incremented whenever a statement is executed via:
	 * - execute()           — INSERT/UPDATE/DELETE/DDL (non-SELECT)
	 * - select()            — SELECT (mysqlnd result)
	 * - selectNoMysqlnd()   — SELECT streamed with bind_result()/fetch()
	 * - queryRaw()          — single raw SQL statement (no binding)
	 * - queryRawMulti()     — one increment per processed statement in the batch
	 *
	 * Usage:
	 * - Read with countQueries() to get the current value.
	 * - Pass true to countQueries(true) to reset the counter after reading.
	 *
	 * Notes:
	 * - This counts executed statements, not rows affected or fetched.
	 * - Intended for lightweight profiling/monitoring in performance-sensitive code.
	 *
	 * @var int
	 */
	private int $queryCount = 0;

	
	
	/**
	 * Creates a new database connection using MySQLi.
	 *
	 * The constructor enables STRICT error reporting, establishes a connection,
	 * and sets the character encoding. All subsequent queries will use this
	 * connection. If the connection or charset initialization fails,
	 * a \mysqli_sql_exception is thrown.
	 *
	 * Note: \mysqli_report() sets a global driver flag for the process. If you need
	 * different behavior in other parts of the app, make this configurable.
	 *
	 * Example:
	 * ```php
	 * $db = new LiteMySQLi('localhost', 'user', 'pass', 'my_database');
	 * ```
	 *
	 * @param string $host     The database server hostname or IP address.
	 * @param string $username The database username.
	 * @param string $password The database password.
	 * @param string $database The name of the database to connect to.
	 * @param string $charset  The character set for the connection (default: 'utf8mb4').
	 *
	 * @throws \mysqli_sql_exception If connection or charset setup fails.
	 */
	public function __construct(string $host, string $username, string $password, string $database, string $charset = 'utf8mb4') {
		
		// Set mysqli error reporting mode to exception, warning or none.
		// When set to MYSQLI_REPORT_ALL or MYSQLI_REPORT_INDEX it will also inform about queries that don't use an index (or use a bad index).
		// The default setting is MYSQLI_REPORT_OFF.
		// Further reading: https://www.php.net/manual/en/mysqli-driver.report-mode.php
		//
		// The options are:
		// ----------------
		// MYSQLI_REPORT_OFF	// Turns reporting off (the default)
		// MYSQLI_REPORT_ERROR	// Report errors from mysqli function calls
		// MYSQLI_REPORT_STRICT	// Throw mysqli_sql_exception for errors instead of warnings
		// MYSQLI_REPORT_INDEX	// Report if no index or bad index was used in a query
		// MYSQLI_REPORT_ALL	// Set all options (report all)
		
		// Enable MySQLi error reporting to throw exceptions on errors
		\mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

		// Establish a new MySQLi connection
		$this->connection = new \mysqli($host, $username, $password, $database);

		// Set the desired character set for the connection
		$this->connection->set_charset($charset);
	}


	/**
	 * Executes a SELECT query and returns the value of the first column
	 * from the first row of the result set.
	 *
	 * Useful for scalar queries such as COUNT(*), MAX(id), or when selecting
	 * a single column. Returns null if no rows are found.
	 *
	 * Example:
	 * ```php
	 * $doh = $db->fetchValue("SELECT COUNT(*) FROM commits WHERE message = ?", ['fix later']);
	 * $email = $db->fetchValue("SELECT email FROM users WHERE author = ?", ['anonymous']);
	 * ```
	 *
	 * @param string $sql    The SELECT query to execute.
	 * @param array  $params Optional parameters to bind for the prepared statement.
	 * @return mixed|null    The first column value, or null if no rows were found.
	 *
	 * @throws \mysqli_sql_exception If query execution fails.
	 */
	public function fetchValue(string $sql, array $params = []) {
		// Execute the query and obtain a result set
		$result = $this->select($sql, $params);

		$value = null;
		// Fetch the first row as a numeric array and capture the first column
		if ($row = $result->fetch_row()) {
			$value = $row[0];
		}

		// Free result resources to release memory
		$result->free();

		// Return the scalar value or null if no row was found
		return $value;
	}


	/**
	 * Executes a SELECT query and returns the first row as an associative array.
	 *
	 * Useful when you only need one record. Returns null if no rows are found.
	 *
	 * Example:
	 * ```php
	 * $excuse = $db->fetchRow("SELECT * FROM excuses WHERE developer = ? LIMIT 1", ['junior']);
	 * if ($excuse) {
	 *     echo $excuse['It works on my machine!'];
	 * }
	 * ```
	 *
	 * @param string $sql    The SELECT query to execute.
	 * @param array  $params Optional parameters to bind for the prepared statement.
	 * @return array|null    The first row as an associative array, or null if no rows exist.
	 *
	 * @throws \mysqli_sql_exception If query execution fails.
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		// Execute the query and obtain a result set
		$result = $this->select($sql, $params);

		// Fetch the first row as an associative array
		$row = $result->fetch_assoc();

		// Free result resources to release memory
		$result->free();

		// Return the row or null if no rows were found
		return $row ?: null;
	}


	/**
	 * Executes a SELECT query and returns all rows as an array of associative arrays.
	 *
	 * Requires mysqlnd for get_result()/fetch_all(). If mysqlnd is unavailable,
	 * use a streaming approach (bind_result()/fetch()) or selectNoMysqlnd().
	 *
	 * Useful when retrieving full result sets. For very large result sets,
	 * consider streaming rows with fetchRow() in a loop instead of fetchAll().
	 *
	 * Example:
	 * ```php
	 * $meetings = $db->fetchAll("SELECT id, subject FROM calendar WHERE day = ?", ['Friday']);
	 * foreach ($meetings as $meeting) {
	 *     echo $meeting['subject'] . " // could have been an email\n";
	 * }
	 * ```
	 *
	 * @param string $sql    The SELECT query to execute.
	 * @param array  $params Optional parameters to bind for the prepared statement.
	 * @return array         Array of associative arrays (possibly empty if no rows found).
	 *
	 * @throws \mysqli_sql_exception If query execution fails.
	 */
	public function fetchAll(string $sql, array $params = []): array {
		// Execute the query and obtain a result set
		$result = $this->select($sql, $params);

		// Fetch all rows as an array of associative arrays
		$data = $result->fetch_all(MYSQLI_ASSOC);

		// Free result resources to release memory
		$result->free();

		// Return all rows as an array (empty if no results)
		return $data;
	}
	

	/**
	 * Counts the number of rows from either a mysqli_result object or a SELECT query.
	 *
	 * Behavior:
	 * - If a mysqli_result object is passed, returns its row count directly.
	 * - If a SQL query string is passed, executes it as a SELECT and returns the row count.
	 *   The result set is freed automatically after counting.
	 *
	 * Returns 0 if the result set is empty.
	 *
	 * Example:
	 * ```php
	 * // Count rows from an existing result
	 * $result = $db->select("SELECT * FROM bugs WHERE status = ?", ['open']);
	 * echo $db->countRows($result); // e.g. too many
	 *
	 * // Count rows directly from a SQL string
	 * echo $db->countRows("SELECT * FROM bugs WHERE status = ?", ['fixed_in_prod']); // DOH!
	 * ```
	 *
	 * @param \mysqli_result|string $resultOrSql Either an existing mysqli_result or a SQL SELECT query string.
	 * @param array                 $params      Parameters to bind if $resultOrSql is SQL.
	 * @return int Number of rows in the result set (0 if none).
	 *
	 * @throws \mysqli_sql_exception If query execution fails.
	 */
	public function countRows($resultOrSql, array $params = []): int {
		// If a mysqli_result object is provided, return its row count directly
		if ($resultOrSql instanceof \mysqli_result) {
			return $resultOrSql->num_rows;
		}

		// Otherwise, execute the query and count the rows
		$result = $this->select($resultOrSql, $params);
		$count  = $result->num_rows;

		// Free the result set to release memory promptly
		$result->free();

		return $count;
	}


	/**
	 * Checks whether at least one row exists in a table matching a WHERE condition.
	 *
	 * Uses a lightweight "SELECT 1 ... LIMIT 1" pattern for minimal overhead.
	 * Returns true if a row exists, false otherwise.
	 *
	 * Example:
	 * ```php
	 * if ($db->exists('passwords', 'value = ?', ['123456'])) {
	 *     echo "Still in use.";
	 * }
	 * ```
	 *
	 * Note: The $where expression is passed verbatim. Quote any 
	 * identifiers inside $where yourself if needed.
	 *
	 * @param string $table  The table to query (can include schema).
	 * @param string $where  The WHERE clause to filter rows (with placeholders).
	 * @param array  $params Parameters for the WHERE clause.
	 * @return bool True if at least one matching row exists, otherwise false.
	 *
	 * @throws \InvalidArgumentException If the identifier is invalid.
	 * @throws \mysqli_sql_exception    If the query execution fails.
	 */
	public function exists(string $table, string $where, array $params = []): bool {
		// Construct SELECT query with LIMIT 1
		$sql = "SELECT 1 FROM " . $this->quoteIdentifierPath($table) . " WHERE $where LIMIT 1";

		// Execute the query
		$result = $this->select($sql, $params);
		
		// True if at least one row was returned
		$found  = ($result->num_rows > 0);

		// Free resources
		$result->free();

		return $found;
	}
	
	
	/**
	 * Inserts a single row into the specified database table.
	 *
	 * The method safely quotes identifiers (table and column names), prepares
	 * an INSERT statement, binds parameter values, and executes it.
	 * It returns the auto-generated ID from the last inserted row.
	 *
	 * Example:
	 * ```php
	 * $id = $db->insert('users', [
	 *     'name'  => 'Anders And',
	 *     'email' => 'anders@disney.com'
	 * ]);
	 * ```
	 *
	 * @param string $table The name of the database table (can include schema).
	 * @param array  $data  Associative array of column-value pairs to insert.
	 * @return int The ID of the last inserted row.
	 *
	 * @throws \InvalidArgumentException If the data array is empty or contains invalid identifiers.
	 * @throws \mysqli_sql_exception    If the query execution fails.
	 */
	public function insert(string $table, array $data): int {
	
		// Initial validation and fail fast
		if ($data === []) {
			throw new \InvalidArgumentException('Data array cannot be empty.');
		}

		// Quote column names to ensure safe identifiers
		$cols = array_map([$this, 'quoteIdentifier'], array_keys($data));
		$columns = implode(',', $cols);

		// Create placeholders (?, ?, ?) for prepared statement
		$placeholders = implode(',', array_fill(0, count($data), '?'));

		// Construct INSERT query with quoted table and columns
		$sql = "INSERT INTO " . $this->quoteIdentifierPath($table) . " ($columns) VALUES ($placeholders)";

		// Execute query with bound values
		$this->execute($sql, array_values($data));

		// Return auto-increment ID
		return $this->connection->insert_id;
	}


	/**
	 * Efficiently inserts multiple rows into a table using the best strategy for the payload size.
	 *
	 * This method chooses between a single multi-row INSERT (one round trip) and a chunked execution
	 * of a single-row INSERT inside a transaction (many round trips, but memory-safe), based on a
	 * coarse payload estimate and a row-count threshold.
	 *
	 * Behavior:
	 * - Column set and order are fixed from the first row; every row must have the exact same keys.
	 * - Identifiers (table and columns) are safely quoted.
	 * - Values are bound as prepared-statement parameters.
	 * - Returns the number of inserted rows as reported by MySQLi.
	 *
	 * IMPORTANT:
	 * - The coarse size estimate is conservative and intended to avoid oversized packets.
	 * - It does not perfectly account for protocol overhead or per-type encoding.
	 *
	 * Example:
	 * ```php
	 * $rows = $db->insertBatch('users', [
	 *     ['email' => 'anders@andeby.dk', 'active' => 1],
	 *     ['email' => 'andersine@andeby.dk', 'active' => 1],
	 *     // ...
	 * ]);
	 * ```
	 *
	 * NOTES:
	 * - Consider chunking to respect `max_allowed_packet`! For very large batches, this method
	 *   already chunks the fallback path. You can also split `$data` yourself (e.g., 5–20k rows
	 *   depending on row size) and call `insertBatch()` per chunk for tighter control.
	 * - This method opens and commits ITS OWN TRANSACTION(!) in the chunked fallback path.
	 *   Do NOT call `insertBatch()` inside an already active transaction; start/end
	 *   the transaction outside if you need to combine multiple operations atomically.
	 *
	 *   Example:
	 *   ```php
	 *   $db->beginTransaction();
	 *   try {
	 *       $db->insertBatch('features', $rows); // all merged to main
	 *       $db->update('release', ['status' => 'live'], 'id = ?', [$jobId]);
	 *       $db->commit(); // launch party
	 *   } catch (\Throwable $e) {
	 *       $db->rollback(); // hotfix incoming
	 *       throw $e;
	 *   }
	 *
	 * @param string $table The target table name (may be schema-qualified, e.g. "db.users").
	 * @param array  $data  List of associative arrays (one per row). Keys = column names; all rows must match.
	 * @return int Number of rows inserted.
	 *
	 * @throws \InvalidArgumentException If $data is empty, the first row is invalid, or any row's keys differ.
	 * @throws \mysqli_sql_exception    If statement preparation or execution fails.
	 */
	public function insertBatch(string $table, array $data): int {
		// Fail fast: an empty dataset makes no sense
		if ($data === []) {
			throw new \InvalidArgumentException('Data array cannot be empty.');
		}
		// Ensure first element is a non-empty associative array
		if (!is_array($data[0]) || $data[0] === []) {
			throw new \InvalidArgumentException('First row must be a non-empty associative array.');
		}

		// Fix the column set and order based on the first row.
		// Assumes all rows must share the same keys (exact match).
		$columns = array_keys($data[0]);

		// Validate that every row has exactly the same set of keys (order may differ).
		$expected = array_fill_keys($columns, true);
		foreach ($data as $idx => $row) {
			if (!is_array($row)) {
				throw new \InvalidArgumentException("Row #{$idx} is not an array.");
			}
			// Missing or extra keys?
			$keys = array_keys($row);
			if (array_diff_key($expected, array_fill_keys($keys, true)) !== []
				|| array_diff_key(array_fill_keys($keys, true), $expected) !== []) {
				throw new \InvalidArgumentException("Row #{$idx} has a different column set than the first row.");
			}
		}

		// Quote column identifiers safely for the INSERT list
		$quotedCols = array_map([$this, 'quoteIdentifier'], $columns);
		$columnList = implode(',', $quotedCols);

		// Coarse payload estimate (bytes) to guide strategy:
		// - strings: actual byte length
		// - other scalars/null: ~8 bytes
		// - add a small per-row overhead (commas/parentheses)
		$estimatedBytes = 0;
		foreach ($data as $row) {
			foreach ($columns as $col) {
				$val = $row[$col] ?? null;
				$estimatedBytes += is_string($val) ? strlen($val) : 8;
			}
			$estimatedBytes += 16; // conservative per-row overhead for separators
		}

		// Heuristic thresholds — tune for your environment:
		// - Above ROW_LIMIT or SIZE_LIMIT, prefer the chunked fallback strategy.
		$ROW_LIMIT  = 1000;             // multi-row above this becomes unwieldy
		$SIZE_LIMIT = 4 * 1024 * 1024;  // ~4MB safety margin under max_allowed_packet

		// --- Strategy 1: multi-row INSERT (one statement, one round trip) ---
		if (count($data) <= $ROW_LIMIT && $estimatedBytes <= $SIZE_LIMIT) {
			// Build a row placeholder: "(?, ?, ...)"
			$rowPh = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

			// Repeat the row placeholder N times
			$placeholders = implode(', ', array_fill(0, count($data), $rowPh));

			// Flatten all values into a single parameter list (column order is fixed)
			$values = [];
			foreach ($data as $row) {
				foreach ($columns as $col) {
					$values[] = $row[$col] ?? null; // preserve NULL explicitly
				}
			}

			// Construct and execute the single multi-row INSERT
			$sql = "INSERT INTO " . $this->quoteIdentifierPath($table) . " ($columnList) VALUES $placeholders";
			return $this->execute($sql, $values);
		}

		// --- Strategy 2: chunked fallback with executeMany() inside one transaction ---
		// Build a single-row INSERT and execute it repeatedly in chunks to limit peak memory
		// and keep packets comfortably below max_allowed_packet.
		$sqlSingle = "INSERT INTO " . $this->quoteIdentifierPath($table)
			. " ($columnList) VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")";

		// Choose a conservative chunk size; adjust for larger/smaller rows as needed.
		$CHUNK_ROWS = 1000;

		// Begin one transaction for the entire batch for atomicity and speed
		// NOTE: Do not wrap insertBatch() inside an existing transaction.
		$this->beginTransaction();
		try {
			$total = 0;

			// Process the dataset in fixed-size chunks to cap memory and packet size
			for ($offset = 0, $n = count($data); $offset < $n; $offset += $CHUNK_ROWS) {
				$chunk = array_slice($data, $offset, $CHUNK_ROWS);

				// Build parameter sets for this chunk (in fixed column order)
				$paramSets = [];
				foreach ($chunk as $row) {
					$params = [];
					foreach ($columns as $col) {
						$params[] = $row[$col] ?? null;
					}
					$paramSets[] = $params;
				}

				// Execute this chunk efficiently on the cached statement
				$total += $this->executeMany($sqlSingle, $paramSets);
			}

			// Commit once the full batch has been processed
			$this->commit();
			return $total;

		} catch (\Throwable $e) {
			// Roll back on any error to avoid partial inserts
			$this->rollback();
			throw $e;
		}
	}
	
	
	/**
	 * Updates rows in the specified database table.
	 *
	 * This method constructs a safe SQL UPDATE statement by quoting identifiers,
	 * preparing the statement, and binding both column values and WHERE clause
	 * parameters securely. It returns the number of affected rows.
	 *
	 * Example:
	 * ```php
	 * $rows = $db->update('users',
	 *     ['name' => 'Lina Rafn', 'email' => 'lina@infernal.dk'],
	 *     'id = ?',
	 *     [42]
	 * );
	 * ```
	 *
	 * @param string $table  The table to update (can include schema).
	 * @param array  $data   Associative array of column-value pairs to set.
	 * @param string $where  The WHERE clause to filter rows (with placeholders).
	 * @param array  $params Parameters for the WHERE clause.
	 * @return int The number of affected rows.
	 *
	 * @throws \InvalidArgumentException If the data array is empty or identifiers are invalid.
	 * @throws \mysqli_sql_exception    If the query execution fails.
	 */
	public function update(string $table, array $data, string $where, array $params = []): int {
		// Initial validation of input and fail fast
		if ($data === []) {
			throw new \InvalidArgumentException('Data array cannot be empty.');
		}

		// Build SET clause: "col1 = ?, col2 = ?"
		$set = implode(',', array_map(
			fn($col) => $this->quoteIdentifier($col) . " = ?",
			array_keys($data)
		));

		// Construct UPDATE query
		$sql = "UPDATE " . $this->quoteIdentifierPath($table) . " SET $set WHERE $where";

		// Merge values and WHERE params
		$allParams = array_merge(array_values($data), $params);

		// Execute and return number of affected rows
		return $this->execute($sql, $allParams);
	}


	/**
	 * Deletes one or more rows from the specified database table.
	 *
	 * Quotes the table identifier, appends the user-provided WHERE clause,
	 * and executes a DELETE query with bound parameters.
	 * It returns the number of affected rows.
	 *
	 * Example:
	 * ```php
	 * $deleted = $db->delete('pizza_toppings', 'name = ?', ['pineapple']);
	 * ```
	 *
	 * @param string $table  The table to delete from (can include schema).
	 * @param string $where  The WHERE clause to filter rows (with placeholders).
	 * @param array  $params Parameters for the WHERE clause.
	 * @return int The number of deleted rows.
	 *
	 * @throws \InvalidArgumentException If the identifier is invalid.
	 * @throws \mysqli_sql_exception    If the query execution fails.
	 */
	public function delete(string $table, string $where, array $params = []): int {
		// Construct DELETE query with quoted table name
		$sql = "DELETE FROM " . $this->quoteIdentifierPath($table) . " WHERE $where";

		// Execute and return number of affected rows
		return $this->execute($sql, $params);
	}
	
	
	/**
	 * Starts a new database transaction.
	 *
	 * All subsequent queries will be part of this transaction until
	 * a commit() or rollback() is executed. Requires a transactional
	 * storage engine such as InnoDB.
	 *
	 * Example:
	 * ```php
	 * $db->beginTransaction();
	 * try {
	 *     $db->update('carts', ['status' => 'checked_out'], 'id = ?', [$cartId]);
	 *     $db->insert('orders', ['cart_id' => $cartId, 'total' => 49.99]);
	 *     $db->commit(); // payment accepted
	 * } catch (\Throwable $e) {
	 *     $db->rollback(); // declined card
	 *     throw $e;
	 * }
	 * ```
	 *
	 * @throws \mysqli_sql_exception If the transaction cannot be started.
	 */
	public function beginTransaction(): void {
		// Start a new transaction
		$this->connection->begin_transaction();
	}


	/**
	 * Commits the current database transaction.
	 *
	 * If a transaction is active, this method commits all changes made during
	 * the transaction. If no transaction is active, calling this method has no effect.
	 *
	 * Example:
	 * ```php
	 * $db->beginTransaction();
	 * $db->insert('users', ['name' => 'Oliver']);
	 * $db->commit();
	 * ```
	 *
	 * @throws \mysqli_sql_exception If the commit operation fails.
	 */
	public function commit(): void {
		// Commit the current transaction
		$this->connection->commit();
	}


	/**
	 * Rolls back the current database transaction.
	 *
	 * If a transaction is active, this method reverts all changes made since
	 * the last `beginTransaction()` call. If no transaction is active,
	 * calling this method has no effect.
	 *
	 * Example:
	 * ```php
	 * $db->beginTransaction();
	 * $db->insert('users', ['name' => 'Laura']);
	 * // Something goes wrong...
	 * $db->rollback(); // Undo the insert
	 * ```
	 *
	 * @throws \mysqli_sql_exception If the rollback operation fails.
	 */
	public function rollback(): void {
		// Revert all uncommitted database changes
		$this->connection->rollback();
	}


	/**
	 * Executes a database transaction using a user-defined callback.
	 *
	 * This method starts a new transaction, executes the provided callback,
	 * and commits if the callback completes successfully. If the callback throws
	 * an exception, the transaction is rolled back and the exception is rethrown
	 * for the caller to handle.
	 *
	 * This ensures that either all operations inside the callback are committed
	 * as a single unit, or none of them are (atomicity).
	 *
	 * Example:
	 * ```php
	 * // Successful transaction (committed)
	 * $db->easyTransaction(function($db) {
	 *     $db->insert('week', ['day' => 'Friday', 'energy' => 'Finally weekend!']);
	 *     $db->insert('paychecks', ['week_id' => $db->lastInsertId(), 'amount' => 1000]);
	 * });
	 *
	 * // Failing transaction (rolled back automatically)
	 * try {
	 *     $db->easyTransaction(function($db) {
	 *         $db->insert('week', ['day' => 'Monday']);
	 *         throw new RuntimeException("Nope, rollback to Sunday.");
	 *     });
	 * } catch (\Throwable $e) {
	 *     // Handle error, but database changes are already rolled back
	 * }
	 * ```
	 *
	 * @param callable $callback A function that performs multiple queries within the transaction.
	 *                           The function receives the database instance ($this) as an argument.
	 *
	 * @throws \Throwable If the callback throws an exception, the transaction is rolled back
	 *                    and the exception is rethrown unchanged.
	 */
	public function easyTransaction(callable $callback): void {
		// Begin a new transaction
		$this->connection->begin_transaction();

		try {
			// Execute user-defined callback with this DB instance
			$callback($this);

			// Commit if everything ran without exception
			$this->connection->commit();

		} catch (\Throwable $e) {
			// Roll back to prevent partial changes
			$this->connection->rollback();

			// Rethrow so the caller/framework can handle it
			throw $e;
		}
	}
	
	
	/**
	 * Executes a raw SQL query without prepared statements.
	 *
	 * Intended as an escape hatch for rare cases where prepared statements are
	 * impractical or not supported by MySQLi for the specific statement
	 * (e.g., DDL like CREATE/ALTER/DROP, administrative commands, or quick debug).
	 *
	 * SECURITY NOTE:
	 * - Do not pass untrusted input to this method. It performs no parameter binding.
	 *
	 * Behavior:
	 * - Increments the internal query counter.
	 * - Returns \mysqli_result for SELECT-like statements, or true/false for others.
	 * - Errors will throw \mysqli_sql_exception if mysqli_report is configured with
	 *   MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (recommended).
	 *
	 * Example:
	 * ```php
	 * // Run a DDL statement (no result set, returns true on success)
	 * $db->queryRaw("CREATE TEMPORARY TABLE time_travelers (id INT, name VARCHAR(50))");
	 *
	 * // Insert a row (returns true on success)
	 * $db->queryRaw("INSERT INTO time_travelers (id, name) VALUES (1, 'Marty McFly')");
	 *
	 * // Run a SELECT (returns \mysqli_result)
	 * $res = $db->queryRaw("SELECT id, name FROM time_travelers");
	 * while ($row = $res->fetch_assoc()) {
	 *     echo $row['id'] . ': ' . $row['name'] . PHP_EOL;
	 * }
	 * $res->free();
	 * ```
	 *
	 * @param string $sql Raw SQL to execute (no placeholders, no binding).
	 * @return \mysqli_result|bool Result set for queries that produce one, otherwise true on success or false on failure.
	 *
	 * @throws \mysqli_sql_exception If execution fails and mysqli is in STRICT error mode.
	 */
	public function queryRaw(string $sql) {
		$this->queryCount++;
		return $this->connection->query($sql);
	}


	/**
	 * Executes multiple raw SQL statements in one call via MySQLi::multi_query().
	 *
	 * Each statement must be separated by a semicolon. This method is intended for
	 * administrative/migration use cases (DDL, seeding, maintenance) where prepared
	 * statements are not practical. **Do not** pass untrusted input; there is no
	 * parameter binding.
	 *
	 * Behavior:
	 * - Sends all statements to the server in a single round trip.
	 * - Collects results in order:
	 *   - For statements that produce a result set (e.g., SELECT/SHOW), returns a \mysqli_result.
	 *   - For statements without a result set (e.g., INSERT/UPDATE/DDL), returns true.
	 * - Increments the internal query counter once per processed statement.
	 * - If any statement fails mid-batch, remaining results are drained to keep the
	 *   connection in sync, and a \mysqli_sql_exception is thrown.
	 *
	 * Memory & resource notes:
	 * - Large \mysqli_result objects can consume significant memory. Callers should free
	 *   them promptly with `$result->free()` when finished. This method does not free results.
	 *
	 * Example:
	 * ```php
	 * $results = $db->queryRawMulti("
	 *   CREATE TABLE adventure (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50));
	 *   INSERT INTO adventure (name) VALUES ('Bill'), ('Ted');
	 *   SELECT * FROM adventure;
	 * ");
	 *
	 * foreach ($results as $res) {
	 *   if ($res instanceof \mysqli_result) {
	 *     while ($row = $res->fetch_assoc()) {
	 *       // process excellent $row
	 *     }
	 *     $res->free(); // Important for large result sets
	 *   }
	 * }
	 * ```
	 *
	 * @param string $sql Multiple raw SQL statements separated by semicolons.
	 * @return array<int,\mysqli_result|bool> Ordered results; \mysqli_result for result-producing
	 *                                        statements, or true for statements without a result set.
	 *
	 * @throws \mysqli_sql_exception If any statement fails. Remaining results are drained before throwing
	 *                               to keep the connection usable.
	 */
	public function queryRawMulti(string $sql): array {
		$results = [];

		// Cheap guard: empty input yields no work and an empty result list.
		if (trim($sql) === '') {
			return $results;
		}

		// Dispatch all statements to the server in a single round trip.
		// Behavior:
		// - In STRICT mode (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT), 
		//   mysqli::multi_query() will throw a mysqli_sql_exception immediately if the batch fails.
		// - In non-STRICT mode, it simply returns false without throwing. In that case we
		//   normalize behavior by throwing a mysqli_sql_exception ourselves, so the caller
		//   never sees a silent failure or an empty result list.
		if (!$this->connection->multi_query($sql)) {
			throw new \mysqli_sql_exception(
				"multi_query dispatch failed: {$this->connection->error}",
				$this->connection->errno
			);
		}

		// Iterate through all server-side results in order.
		do {
			// store_result() returns a \mysqli_result for result-producing statements (SELECT/SHOW),
			// or false for statements that do not produce a result set (INSERT/UPDATE/DDL).
			$res = $this->connection->store_result();

			// Normalize per-statement outcome to either a result set object or a boolean placeholder.
			$results[] = ($res instanceof \mysqli_result) ? $res : true;

			// Count each processed statement for lightweight profiling/monitoring.
			$this->queryCount++;

			// Continue while the server reports more results and we can advance to the next one.
		} while ($this->connection->more_results() && $this->connection->next_result());

		// If any statement in the batch failed mid-sequence, errno will be non-zero here.
		if ($this->connection->errno !== 0) {
			// Drain any unread results to resynchronize the connection state so callers can keep using it.
			while ($this->connection->more_results() && $this->connection->next_result()) {
				if ($r = $this->connection->store_result()) {
					$r->free();
				}
			}

			// Surface the error after cleanup. This preserves connection usability for subsequent operations.
			throw new \mysqli_sql_exception(
				"multi_query failed: {$this->connection->error}",
				$this->connection->errno
			);
		}

		return $results;
	}
	
	
	/**
	 * Executes a SELECT query and returns the mysqli_result.
	 *
	 * Prepares a (possibly cached) prepared statement, binds parameters, executes it,
	 * and returns an active result set. The caller is responsible for freeing the result.
	 *
	 * IMPORTANT:
	 * - You MUST call `$result->free()` once you are done reading the rows.
	 * - If you plan to re-execute the exact same SQL string (which maps to the same
	 *   cached \mysqli_stmt) on this connection, free the previous result first,
	 *   otherwise MySQLi may report: "Commands out of sync; you can't run this command now".
	 *
	 * Requirements:
	 * - Requires mysqlnd for `get_result()` / `fetch_all()`. If mysqlnd is unavailable,
	 *   use {@see selectNoMysqlnd()} to stream rows with `bind_result()`/`fetch()`.
	 *
	 * Example:
	 * ```php
	 * $result = $db->select('SELECT id, title FROM bugs WHERE status = ?', [1]);
	 * while ($row = $result->fetch_assoc()) {
	 *     echo $row['title'] . " // definitely a feature\n";
	 * }
	 * $result->free(); // IMPORTANT: free before reusing the same SQL/cached statement
	 * ```
	 *
	 * @param string $sql    The SELECT query to execute (with ? placeholders if needed).
	 * @param array  $params Parameters to bind to the prepared statement, in placeholder order.
	 * @return \mysqli_result Active result set (caller must call `$result->free()`).
	 *
	 * @throws \mysqli_sql_exception If preparing, binding, execution, or result retrieval fails.
	 */
	public function select(string $sql, array $params = []): \mysqli_result {
		$this->queryCount++;

		$stmt = $this->getPreparedStatement($sql);
		$this->bindParams($stmt, $params);
		$stmt->execute();

		$result = $stmt->get_result();
		if (!$result) {
			throw new \mysqli_sql_exception('Expected a result set from SELECT, but none was produced.');
		}
		return $result;
	}


	/**
	 * Executes a SELECT query without relying on mysqlnd and streams rows.
	 *
	 * This method prepares the statement, binds parameters, and streams the result
	 * row-by-row using `bind_result()/fetch()`. It yields each row as a detached
	 * associative array. The underlying prepared statement is guaranteed to be
	 * closed via a `finally` block when the generator is exhausted or garbage-collected.
	 *
	 * Use this method when:
	 * - mysqlnd is unavailable (thus `get_result()`/`fetch_all()` cannot be used), or
	 * - you want to stream large result sets with low peak memory usage.
	 *
	 * IMPORTANT:
	 * - This method bypasses the statement cache intentionally to simplify lifetime
	 *   management of the streaming statement.
	 * - Do not attempt to reuse the same streaming generator concurrently on the
	 *   same connection. Iterate it to completion or break out; either way, the
	 *   statement will be closed by `finally`.
	 * - The `$sql` string should be a valid SELECT producing a result set.
	 *
	 * Example:
	 * ```php
	 * foreach ($db->selectNoMysqlnd('SELECT id, change FROM hotfixes WHERE applied_in = ?', ['prod']) as $row) {
	 *     echo $row['id'] . ' -> ' . $row['change'] . PHP_EOL;
	 * }
	 * ```
	 *
	 * @param string $sql    The SELECT query to execute (with ? placeholders if needed).
	 * @param array  $params Parameters to bind to the prepared statement, in placeholder order.
	 * @return \Generator<array<string,mixed>> Streams each row as an associative array.
	 *
	 * @throws \mysqli_sql_exception If preparation, binding, execution, or metadata retrieval fails.
	 */
	public function selectNoMysqlnd(string $sql, array $params = []): \Generator {
		$this->queryCount++;

		// Always prepare a fresh statement (not cached) to ensure deterministic closure after streaming.
		$stmt = $this->prepareUncached($sql);

		// Bind parameters with minimal overhead and correct typing.
		$this->bindParams($stmt, $params);

		// Execute the prepared SELECT.
		$stmt->execute();

		// Obtain result metadata to discover column names and count.
		$meta = $stmt->result_metadata();
		if (!$meta) {
			// No result set produced (e.g., non-SELECT) – align behavior with select() by throwing.
			$stmt->close();
			throw new \mysqli_sql_exception('Expected a result set from SELECT, but none was produced.');
		}

		// Collect field descriptors and free metadata.
		$fields = $meta->fetch_fields();
		$meta->free();

		// Build an associative buffer for all columns and an array of references for bind_result().
		$row  = [];
		$bind = [];
		foreach ($fields as $f) {
			$row[$f->name] = null;
			$bind[] = &$row[$f->name]; // bind_result requires references
		}

		// Bind all result columns to our references.
		$stmt->bind_result(...$bind);

		// Use a generator with a finally block so the statement is always closed,
		// even if the caller breaks out of the loop early.
		try {
			while ($stmt->fetch()) {
				// Detach the current row from the bound references before yielding.
				$detached = $row;
				yield $detached;
			}
		} finally {
			// Discard any pending buffered rows (if any) and close the statement
			$stmt->free_result(); // safe/no-op if nothing buffered
			$stmt->close();
		}
	}
	
	
	/**
	 * Executes a non-SELECT SQL statement (INSERT/UPDATE/DELETE/DDL).
	 *
	 * Prepares the statement, binds parameters with correct mysqli types,
	 * executes it, and returns the number of affected rows.
	 *
	 * Example:
	 * ```php
	 * $rows = $db->execute('UPDATE coffee_machine SET cups_dispensed = cups_dispensed + 1 WHERE id = ?', [$machineId]);
	 * ```
	 *
	 * @param string $sql    The SQL statement to execute.
	 * @param array  $params Parameters to bind to the prepared statement.
	 * @return int Number of affected rows.
	 *
	 * @throws \mysqli_sql_exception If the statement preparation or execution fails.
	 */
	public function execute(string $sql, array $params = []): int {
		$this->queryCount++;

		// Reuse cached prepared statement if available
		$stmt = $this->getPreparedStatement($sql);

		// Bind parameters (handles NULL, int, float, bool, string)
		$this->bindParams($stmt, $params);

		// Execute and return affected rows
		$stmt->execute();
		return $this->connection->affected_rows;
	}
	
	
	/**
	 * Executes the same prepared SQL statement multiple times with different parameters.
	 *
	 * This method is optimized for scenarios where the same query needs to be run
	 * repeatedly with different values, such as batch inserts, bulk updates, or
	 * applying the same WHERE condition across many parameter sets.
	 *
	 * Behavior:
	 * - Prepares (or reuses a cached) statement for the given SQL string.
	 * - Iterates over each parameter set in `$paramSets`, binding values and executing the statement.
	 * - Increments the internal query counter once per execution.
	 * - Accumulates and returns the total number of affected rows across all executions.
	 *
	 * Performance notes:
	 * - Reduces overhead compared to calling execute() in a loop, as the prepared
	 *   statement is only looked up once and then reused.
	 * - For very large batches, wrap calls in a transaction (BEGIN/COMMIT) to avoid
	 *   per-row fsync overhead and achieve optimal performance.
	 *
	 * Example:
	 * ```php
	 * // Batch update using the same SQL for many IDs
	 * $rows = $db->executeMany(
	 *     'UPDATE users SET active = ? WHERE id = ?',
	 *     [
	 *         [1, 101],
	 *         [1, 102],
	 *         [0, 103],
	 *     ]
	 * );
	 * echo "Total rows updated: " . $rows;
	 * ```
	 *
	 * @param string   $sql       The SQL statement with ? placeholders (prepared once, reused).
	 * @param array[]  $paramSets Array of parameter arrays, each matching the placeholders.
	 *                            Each inner array is bound and executed in order.
	 * @return int Total number of affected rows across all executions.
	 *
	 * @throws \mysqli_sql_exception If statement preparation, binding, or execution fails.
	 */
	public function executeMany(string $sql, array $paramSets): int {
		// Fast-fail: no parameter sets means nothing to execute.
		if ($paramSets === []) return 0;

		// Count each execution attempt toward the global query counter.
		$this->queryCount += count($paramSets);

		// Retrieve a cached prepared statement for the SQL (or prepare it if not cached).
		$stmt = $this->getPreparedStatement($sql);

		// Track total affected rows across all executions.
		$total = 0;

		// Loop through each parameter set and execute the prepared statement.
		foreach ($paramSets as $params) {
			// Ensure no previous result set is left hanging on this statement.
			// (Usually a no-op, but defensive against "commands out of sync".)
			$stmt->free_result();

			// Bind the current parameter values to the statement.
			$this->bindParams($stmt, $params);

			// Execute the statement with the bound parameters.
			$stmt->execute();

			// Add the number of rows affected by this execution to the running total.
			$total += $this->affectedRows();
		}

		// Return the sum of all affected rows across all executions.
		return $total;
	}
	
	
	/**
	 * Retrieves the ID of the last inserted row.
	 *
	 * This method returns the auto-generated ID from the most recent INSERT
	 * operation on a table with an AUTO_INCREMENT column. If the last query
	 * was not an INSERT or if the table has no AUTO_INCREMENT column, 0 is returned.
	 *
	 * Example:
	 * ```php
	 * $id = $db->insert('users', ['name' => 'Sarah']);
	 * echo $db->lastInsertId(); // same as $id
	 * ```
	 *
	 * @return int The ID of the last inserted row, or 0 if none.
	 *
	 * @throws \mysqli_sql_exception If the database connection is lost.
	 */
	public function lastInsertId(): int {
		// Return the last auto-generated ID from an INSERT
		return $this->connection->insert_id;
	}


	/**
	 * Gets the number of rows affected by the last executed SQL statement.
	 *
	 * This method returns the number of rows that were modified, inserted,
	 * or deleted by the most recent query executed on this connection.
	 * Returns 0 if no rows were affected.
	 *
	 * Example:
	 * ```php
	 * $rows = $db->update('trials', ['active' => 0], 'end_date < ?', ['2003-01-01']);
	 * echo $db->affectedRows(); // except WinRAR still going strong! 
	 * ```
	 *
	 * @return int The number of rows affected by the last statement, or 0 if none.
	 *
	 * @throws \mysqli_sql_exception If the connection is invalid or if an error occurs.
	 */
	public function affectedRows(): int {
		// Return the number of rows affected by the most recent SQL statement
		return $this->connection->affected_rows;
	}
	
	
	/**
	 * Returns the number of queries executed by this instance.
	 *
	 * The counter is incremented each time a query is executed via
	 * execute(), select(), queryRaw(), or queryRawMulti().
	 *
	 * Optionally, the counter can be reset to zero after retrieval by
	 * passing `$reset = true`. This is useful when profiling query usage
	 * in long-running scripts or measuring query counts for a specific
	 * code block.
	 *
	 * Example:
	 * ```php
	 * // Profile queries within a block of code
	 * $before = $db->countQueries(true); // reset counter
	 * $users  = $db->fetchAll("SELECT * FROM users");
	 * $after  = $db->countQueries();
	 * echo "Queries executed: " . ($after - $before);  // shockingly, just one
	 * ```
	 *
	 * @param bool $reset Whether to reset the counter after reading (default: false).
	 * @return int The number of queries executed so far (or since last reset).
	 */
	public function countQueries(bool $reset = false): int {
		$count = $this->queryCount;
		if ($reset) {
			$this->queryCount = 0;
		}
		return $count;
	}
	
	
	/**
	 * Returns the last error message reported by MySQLi on this connection.
	 *
	 * If no error has occurred since the last operation, null is returned.
	 * Use together with {@see getLastErrorCode()} to retrieve both code and message.
	 *
	 * Example:
	 * ```php
	 * $db->execute("INVALID SQL");
	 * if ($err = $db->getLastError()) {
	 *     echo "Error: " . $err;
	 * }
	 * ```
	 *
	 * @return string|null The last error message, or null if no error has occurred.
	 */
	public function getLastError(): ?string {
		return $this->connection->error ?: null;
	}

	
	/**
	 * Returns the last error code reported by MySQLi on this connection.
	 *
	 * If no error has occurred since the last operation, 0 is returned.
	 * Use together with {@see getLastError()} to retrieve both code and message.
	 *
	 * Example:
	 * ```php
	 * $db->execute("INVALID SQL");
	 * if ($db->getLastErrorCode() !== 0) {
	 *     echo "Error code: " . $db->getLastErrorCode();
	 *     echo "Error message: " . $db->getLastError();
	 * }
	 * ```
	 *
	 * @return int The last error code, or 0 if no error has occurred.
	 */
	public function getLastErrorCode(): int {
		return $this->connection->errno;
	}
	
	
	/**
	 * Adjusts the maximum size of the statement cache at runtime.
	 *
	 * Behavior:
	 * - If limit is set to 0, caching is disabled and all existing cached
	 *   statements are evicted immediately.
	 * - If limit is reduced, oldest cached statements are evicted (FIFO)
	 *   until the cache size matches the new limit.
	 * - If limit is increased, more unique SQL statements can be cached.
	 *
	 * Example:
	 * ```php
	 * $db->setStatementCacheLimit(256); // allow more cached statements
	 * $db->setStatementCacheLimit(0);   // disable caching completely
	 * ```
	 *
	 * @param int $limit New maximum number of cached statements (>= 0).
	 *
	 * @throws \mysqli_sql_exception If closing evicted statements fails.
	 */
	public function setStatementCacheLimit(int $limit): void {
		// Normalize limit to 0 or higher
		$this->statementCacheLimit = max(0, $limit);

		// Disable caching entirely if limit is 0
		if ($this->statementCacheLimit === 0) {
			$this->clearStatementCache();
			return;
		}

		// Evict oldest statements if current cache exceeds new limit
		while (count($this->statementCache) > $this->statementCacheLimit) {
			$old = array_shift($this->statementCache);
			$old?->close();
		}
	}
	
	
	/**
	 * Clears the statement cache while keeping the database connection open.
	 *
	 * All prepared statements currently stored in the internal cache are closed
	 * and removed. This is useful when:
	 * - You want to free memory without disconnecting.
	 * - You suspect cached statements are stale (e.g. after schema changes).
	 *
	 * The connection itself remains active and can still be used for new queries.
	 *
	 * Example:
	 * ```php
	 * $db->clearStatementCache(); // frees all cached statements, keeps connection alive
	 * ```
	 *
	 * @throws \mysqli_sql_exception If closing a statement fails under STRICT error reporting.
	 */
	public function clearStatementCache(): void {
		foreach ($this->statementCache as $stmt) { $stmt->close(); }
		$this->statementCache = [];
	}
	
	
	/**
	 * Retrieves a prepared statement from the cache or prepares a new one.
	 *
	 * Behavior:
	 * - If caching is disabled (limit = 0), always prepares and returns a fresh statement.
	 * - If a cached statement exists for the exact SQL string, it is reused.
	 * - If the cache is at capacity, the oldest statement is evicted (FIFO) and closed.
	 * - A newly prepared statement is cached (unless caching is disabled) and returned.
	 *
	 * Notes:
	 * - This method does not attempt to “health-check” cached statements;
	 *   if a statement becomes unusable, MySQLi will throw on execute(), which is desired.
	 * - SQL strings are used as 1:1 cache keys; changing whitespace or placeholder
	 *   layout creates a distinct cache entry.
	 *
	 * Example:
	 * ```php
	 * $stmt = $this->getPreparedStatement('SELECT * FROM code WHERE source = ?');
	 * $this->bindParams($stmt, ['stackoverflow']);
	 * $stmt->execute();
	 * $result = $stmt->get_result(); // production ready 
	 * ```
	 *
	 * @param string $sql The SQL string to prepare (with ? placeholders if needed).
	 * @return \mysqli_stmt A prepared (possibly cached) statement ready for binding/execution.
	 *
	 * @throws \mysqli_sql_exception If MySQLi fails to prepare the statement.
	 */
	private function getPreparedStatement(string $sql): \mysqli_stmt {
		// Fast path: Caching disabled -> always prepare a fresh statement
		if ($this->statementCacheLimit === 0) {
			$stmt = $this->connection->prepare($sql);
			if (!$stmt) {
				throw new \mysqli_sql_exception("Prepare failed: {$this->connection->error}");
			}
			return $stmt;
		}

		// Reuse cached statement if we have an exact match
		if (isset($this->statementCache[$sql])) {
			return $this->statementCache[$sql];
		}

		// Cache full? Evict the oldest statement (FIFO) to free space
		if (count($this->statementCache) >= $this->statementCacheLimit) {
			$old = array_shift($this->statementCache);
			$old?->close();
		}

		// Prepare a new statement and cache it under the SQL key
		$stmt = $this->connection->prepare($sql);
		if (!$stmt) {
			throw new \mysqli_sql_exception("Prepare failed: {$this->connection->error}");
		}

		$this->statementCache[$sql] = $stmt;
		return $stmt;
	}
	
	
	/**
	 * Prepares a new mysqli statement without using the statement cache.
	 *
	 * This helper bypasses the internal statement cache entirely and always
	 * returns a fresh prepared statement. It is primarily used in scenarios
	 * where statements must be closed immediately after use (e.g. streaming
	 * large results in selectNoMysqlnd()) and therefore should not remain
	 * in the cache.
	 *
	 * Example:
	 * ```php
	 * $stmt = $this->prepareUncached("SELECT * FROM password_resets WHERE reason = ?");
	 * $this->bindParams($stmt, ['forgot']);
	 * $stmt->execute();
	 * $result = $stmt->get_result();  // every Monday
	 * ```
	 *
	 * @param string $sql The SQL query to prepare (with ? placeholders if needed).
	 * @return \mysqli_stmt A freshly prepared statement (not cached).
	 *
	 * @throws \mysqli_sql_exception If statement preparation fails.
	 */
	private function prepareUncached(string $sql): \mysqli_stmt {
		// Always create a new prepared statement, bypassing the cache
		$stmt = $this->connection->prepare($sql);

		// Throw an exception if preparation fails
		if (!$stmt) {
			throw new \mysqli_sql_exception("Prepare failed: {$this->connection->error}");
		}

		return $stmt;
	}
	
	
	/**
	 * Dynamically binds parameters to a prepared MySQLi statement.
	 *
	 * This method determines the correct MySQLi type for each parameter and binds
	 * them with minimal overhead. It preserves `NULL` values properly, avoiding
	 * the common pitfall where `NULL` integers get converted to `0`.
	 *
	 * Type mapping rules:
	 * - `NULL`   -> type 's', value remains `null` (ensures correct SQL NULL binding)
	 * - `int`    -> type 'i'
	 * - `float`  -> type 'd'
	 * - `bool`   -> converted to int(1 or 0), type 'i'
	 * - `string` -> type 's'
	 * - other    -> cast to string, type 's'
	 *
	 * Example:
	 * ```php
	 * $stmt = $this->getPreparedStatement('SELECT * FROM bugs WHERE id = ? OR steps_to_reproduce IS ?');
	 * $this->bindParams($stmt, [404, null]);
	 * $stmt->execute();
	 * $result = $stmt->get_result(); // classic
	 * ```
	 *
	 * @param \mysqli_stmt $stmt   The prepared statement to bind parameters to.
	 * @param array        $params The parameters to bind, in the same order as placeholders.
	 *
	 * @throws \mysqli_sql_exception If binding fails due to mismatched parameter count or invalid types.
	 */
	private function bindParams(\mysqli_stmt $stmt, array $params): void {
		// If there are no parameters, nothing needs to be bound
		if (!$params) return;

		$types = '';  // type string for bind_param (e.g. "si")
		$refs  = [];  // array of references to parameter values

		// Loop through each parameter and assign correct MySQLi type
		foreach ($params as &$p) {
			if (is_null($p)) {
				// Bind NULL safely as type 's' (mysqli interprets null correctly as SQL NULL)
				$types .= 's';
				$p = null;
			} elseif (is_int($p)) {
				// Integer parameter
				$types .= 'i';
			} elseif (is_float($p)) {
				// Float/Double parameter
				$types .= 'd';
			} elseif (is_bool($p)) {
				// Convert boolean to int(0/1) for safe binding
				$p = $p ? 1 : 0;
				$types .= 'i';
			} else {
				// Fallback: treat everything else as string
				$types .= 's';
			}

			// Add reference to parameter (bind_param requires references)
			$refs[] = &$p;
		}

		// Bind types and parameter values to the prepared statement
		$stmt->bind_param($types, ...$refs);
	}


	/**
	 * Binds parameters to a prepared MySQLi statement using a naive "all strings" approach.
	 *
	 * Unlike {@see bindParams()}, this method does not detect parameter types.
	 * Every parameter is bound as a string (`s`). This avoids type detection overhead
	 * and can be marginally faster, but it also means MySQL will implicitly cast values
	 * (which may affect indexes and query plans).
	 *
	 * Limitations:
	 * - Integers, floats, booleans, and NULL are all bound as strings.
	 * - May lead to unexpected behavior or performance loss for non-string columns.
	 * - Not suitable for binary data (`b`).
	 *
	 * When to use:
	 * - Prototyping or quick experiments where all params are strings.
	 * - Debugging or cases where developer explicitly wants "stringify everything".
	 * - Not recommended for production-critical queries.
	 *
	 * Example:
	 * ```php
	 * $stmt = $db->getPreparedStatement("SELECT * FROM emails WHERE sender = ? AND status = ?");
	 * $db->bindParamsDownAndDirty($stmt, ['noreply@spam.com', 'unread']);
	 * $stmt->execute(); // inbox full again
	 * ```
	 *
	 * @param \mysqli_stmt $stmt   The prepared statement to bind parameters to.
	 * @param array        $params Indexed array of values to bind (all treated as strings).
	 *
	 * @throws \mysqli_sql_exception If binding fails due to mismatched parameter count.
	 */
	private function bindParamsDownAndDirty(\mysqli_stmt $stmt, array $params): void {
		if (empty($params)) {
			return;
		}

		// Build type string (all 's') with same length as parameter count
		$types = str_repeat('s', count($params));

		// Bind all parameters as strings (no type safety, minimal overhead)
		$stmt->bind_param($types, ...$params);
	}
	
	
	/**
	 * Quotes and validates an SQL identifier (e.g., table or column name).
	 *
	 * This helper ensures the given identifier only contains safe characters
	 * (letters, digits, underscore, and dollar sign) and returns it quoted with
	 * backticks to prevent collisions with reserved words and to ensure correct
	 * parsing by MySQL/MariaDB.
	 *
	 * Allowed pattern: `/^[A-Za-z0-9_\$]+$/`
	 *
	 * Example:
	 * ```php
	 * $table = $this->quoteIdentifier('users');
	 * $col   = $this->quoteIdentifier('created_at');
	 * $sql   = "SELECT {$col} FROM {$table} WHERE id = ?";
	 * ```
	 *
	 * @param string $identifier The raw SQL identifier to be validated and quoted.
	 * @return string The safely quoted identifier, e.g. `` `users` `` or `` `created_at` ``.
	 *
	 * @throws \InvalidArgumentException If the identifier contains illegal characters.
	 */
	private function quoteIdentifier(string $identifier): string {
		if (!preg_match('/^[A-Za-z0-9_\$]+$/', $identifier)) {
			throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
		}
		return '`' . $identifier . '`';
	}
	
	
	/**
	 * Quotes and validates a dot-separated SQL identifier path (e.g. schema.table or table.column).
	 *
	 * Each segment of the path is validated against a strict pattern of allowed characters
	 * (letters, digits, underscore, and dollar sign). Valid segments are then individually
	 * quoted with backticks. The resulting string is safe to embed directly in SQL.
	 *
	 * Usage:
	 * - For table names that include schema qualification: "shop.orders"
	 * - For column references with table aliases: "o.id_order"
	 *
	 * Restrictions:
	 * - Identifiers with spaces, hyphens, or other special characters are rejected.
	 * - This is intentional to keep the API safe and performant.
	 *
	 * Example:
	 * ```php
	 * $table = $this->quoteIdentifierPath('shop.orders');  // -> `shop`.`orders`
	 * $col   = $this->quoteIdentifierPath('o.id_order');   // -> `o`.`id_order`
	 * $sql   = "SELECT {$col} FROM {$table} WHERE {$col} = ?";
	 * ```
	 *
	 * @param string $path Dot-separated identifier path (schema.table, table.column, or alias.column).
	 * @return string The quoted and validated identifier path, e.g. `` `shop`.`orders` ``.
	 *
	 * @throws \InvalidArgumentException If any identifier segment contains illegal characters.
	 */
	private function quoteIdentifierPath(string $path): string {
		// Split the path into segments (e.g. "schema.table" -> ["schema", "table"])
		$segments = explode('.', $path);

		// Validate and quote each segment individually
		$quoted = array_map(function (string $seg): string {
			if (!preg_match('/^[A-Za-z0-9_\$]+$/', $seg)) {
				throw new \InvalidArgumentException("Invalid SQL identifier segment: {$seg}");
			}
			return '`' . $seg . '`';
		}, $segments);

		// Rejoin the quoted segments with dots (-> `schema`.`table`)
		return implode('.', $quoted);
	}
	
	
	/**
	 * Closes all cached prepared statements and the underlying database connection.
	 *
	 * This method explicitly releases all server-side resources and closes the
	 * active MySQLi connection. While PHP/MySQLi normally releases resources
	 * automatically at script termination, doing so explicitly here guarantees
	 * deterministic cleanup, which is especially useful in long-running scripts.
	 *
	 * Behavior:
	 * - All cached prepared statements are closed (exceptions on double-close are ignored).
	 * - The internal statement cache is cleared.
	 * - The active MySQLi connection is closed (double-close attempts are silently ignored).
	 * - Calling this method multiple times is safe; subsequent calls have no effect.
	 *
	 * Example:
	 * ```php
	 * $db = new LiteMySQLi('localhost', 'user', 'pass', 'dbname');
	 * // ... perform queries ...
	 * $db->close(); // free all statements and close connection deterministically
	 * ```
	 *
	 * @return void
	 */
	public function close(): void {
		// Close all prepared statements in the cache
		foreach ($this->statementCache as $stmt) {
			try {
				$stmt->close();
			} catch (\Throwable $e) {
				// Ignore double-close or already freed statement resources
			}
		}
		// Clear the cache array after closing
		$this->statementCache = [];

		// Close the database connection
		try {
			$this->connection->close();
		} catch (\Throwable $e) {
			// Ignore double-close or already freed connection resources
		}
	}
	

	/**
	 * Destructor to ensure all database resources are freed deterministically.
	 *
	 * This method guarantees that any remaining cached prepared statements and
	 * the active MySQLi connection are closed when the object is destroyed.
	 * It internally calls {@see close()}, but suppresses and ignores any errors
	 * to prevent exceptions from bubbling up during object destruction.
	 *
	 * Rationale:
	 * - In PHP, throwing exceptions from a destructor can cause fatal errors.
	 * - By wrapping {@see close()} in a try/catch, cleanup is attempted safely,
	 *   and failure to close resources never interrupts script shutdown.
	 * - This provides a final safeguard for resource cleanup in case
	 *   {@see close()} was not called explicitly.
	 *
	 * Example:
	 * ```php
	 * $db = new LiteMySQLi('localhost', 'user', 'pass', 'dbname');
	 * // no explicit $db->close(); needed — resources will be freed automatically
	 * // when $db goes out of scope or script ends.
	 * ```
	 *
	 * @return void
	 */
	public function __destruct() {
		try {
			// Attempt deterministic cleanup of statements and connection
			$this->close();
		} catch (\Throwable $e) {
			// Never allow exceptions to bubble up from a destructor
		}
	}

}

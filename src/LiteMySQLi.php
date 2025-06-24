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
 
class LiteMySQLi {
	
	/**
	* Holds the active database connection.
	* 
	* @var \mysqli The MySQLi connection instance used for database interactions.
	*/
	private \mysqli $connection;
	
	/**
	 * Stores prepared statements to optimize query execution and reduce overhead.
	 * 
	 * @var array<string, \mysqli_stmt> An associative array mapping SQL queries to prepared statements.
	 */
	private array $statementCache = [];

	/**
	 * Tracks the number of executed database queries.
	 *
	 * This counter is incremented each time a query is executed
	 * via the `query()` method. It helps monitor query usage for
	 * performance optimization and debugging purposes.
	 *
	 * @var int The total number of queries executed during the script's execution.
	 */
	private int $queryCount = 0;



	/**
	 * Establishes a connection to the MySQL database using MySQLi.
	 * 
	 * This constructor initializes a database connection, sets the character encoding, 
	 * and enables MySQLi error reporting to throw exceptions for better error handling.
	 *
	 * @param string $host The database server hostname or IP address.
	 * @param string $username The database username.
	 * @param string $password The database password.
	 * @param string $database The name of the database to connect to.
	 * @param string $charset The character set for the connection (default: 'utf8mb4').
	 * @param bool $supportsBinaryData Whether to enable BLOB (binary data) support in parameter binding.
	 * 
	 * @throws \mysqli_sql_exception If the connection fails or setting charset fails.
	 */
	public function __construct(string $host, string $username, string $password, string $database, string $charset = 'utf8mb4') {
		
		// Set mysqli error reporting mode to exception, warning or none.
		// When set to MYSQLI_REPORT_ALL or MYSQLI_REPORT_INDEX it will also inform about queries that don't use an index (or use a bad index).
		// The default setting is MYSQLI_REPORT_OFF.
		// Further reading: https://www.php.net/manual/en/mysqli-driver.report-mode.php
		
		// The options are:
		
		// MYSQLI_REPORT_OFF	// Turns reporting off (the default)
		// MYSQLI_REPORT_ERROR	// Report errors from mysqli function calls
		// MYSQLI_REPORT_STRICT	// Throw mysqli_sql_exception for errors instead of warnings
		// MYSQLI_REPORT_INDEX	// Report if no index or bad index was used in a query
		// MYSQLI_REPORT_ALL	// Set all options (report all)
		
		// Enable MySQLi error reporting to throw exceptions on errors
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
		
		// Establish a new MySQLi database connection
		$this->connection = new \mysqli($host, $username, $password, $database);
        
		// if ($this->connection->connect_error) {
			// throw new \RuntimeException("Connection failed: " . $this->connection->connect_error);
		// }
		
		// Set the character encoding for the connection
		$this->connection->set_charset($charset);
	}
	
	

	/**
	 * Executes a SQL query, either as a raw query or a prepared statement with bound parameters.
	 *
	 * - If `$params` is empty, the query runs directly using `query()`.
	 * - If `$params` is provided, a prepared statement is created, and parameters are bound safely.
	 *
	 * @param string $sql The SQL query to execute.
	 * @param array $params Optional associative array of parameters to bind in the query.
	 * @return \mysqli_result|bool The result set for SELECT queries or a boolean for non-SELECT queries.
	 * @throws \mysqli_sql_exception If the query fails.
	 */
	public function query(string $sql, array $params = []) {
		
		// Increment the query counter to track executed database queries.
		$this->queryCount++;
		
		// If no parameters are provided, execute the raw SQL directly.
		if (empty($params)) {
			return $this->connection->query($sql);
		}
		
		// Retrieve a prepared statement from cache or create a new one.
		$stmt = $this->getPreparedStatement($sql);		
		// $stmt = $this->connection->prepare($sql);
        
		// Bind the provided parameters to the statement.
		$this->bindParams($stmt, $params);
		
		// Execute the prepared statement.
		$stmt->execute();
		
		// Retrieve and return the result set.
		return $stmt->get_result();
	}
	
	/**
	 * Retrieves the total number of executed database queries.
	 *
	 * This method returns the number of queries that have been executed
	 * since the database connection was established. It is useful for 
	 * performance monitoring and debugging.
	 *
	 * @return int The total count of executed queries.
	 */
	public function countQueries(): int {
		return $this->queryCount; // Return the tracked query count.
	}	

	/**
	 * Executes a SQL query and fetches a single row as an associative array.
	 *
	 * This method prepares and executes the given SQL query with optional bound parameters.
	 * It returns the first row of the result set or `null` if no rows are found.
	 *
	 * @param string $sql The SQL query to execute.
	 * @param array $params Optional parameters to bind to the query.
	 * @return array|null The fetched row as an associative array, or null if no rows are found.
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		
		// Execute query and retrieve result set
		$result = $this->query($sql, $params);
		
		// Return the first row or null if no rows exist
		// return $result->fetch_assoc() ?: null;
			
	    // Fetch the first row as an associative array
		$row = $result->fetch_assoc();
		
		// Free the result set to release memory
		$result->free();
		
		// Return the fetched row or null if no rows were found
		return $row ?: null;
	}

	/**
	 * Executes a query and retrieves all matching rows as an associative array.
	 *
	 * This method runs the given SQL query with optional bound parameters 
	 * and returns all result rows in an associative array format.
	 *
	 * @param string $sql The SQL query to execute.
	 * @param array $params Optional parameters to bind for prepared statements.
	 * @return array An array of associative arrays representing the result set.
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function fetchAll(string $sql, array $params = []): array {
		
		// Execute the query with the provided parameters, returning a MySQLi result object
		$result = $this->query($sql, $params);
		
		// Fetch all rows as an associative array and return
		// return $result->fetch_all(MYSQLI_ASSOC);
		
		// Fetch all rows from the result set as an associative array
		$data = $result->fetch_all(MYSQLI_ASSOC);
		
		// Explicitly free the MySQLi result object to release memory
		$result->free();
		
		// Return the fetched data as an array of associative arrays
		return $data;
	}

	/**
	 * Inserts a new row into the specified database table.
	 *
	 * This method constructs an SQL INSERT statement dynamically 
	 * using the provided data and executes it as a prepared statement.
	 *
	 * @param string $table The name of the database table to insert into.
	 * @param array $data An associative array where keys are column names and values are the corresponding values to insert.
	 * @return int The ID of the last inserted row.
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function insert(string $table, array $data): int {
		
		// Convert array keys (column names) into a comma-separated string
		$columns = implode(',', array_keys($data));
		
		// Generate placeholders (`?`) for each value in the data array
		$placeholders = implode(',', array_fill(0, count($data), '?'));
		
		// Construct the SQL query
		$sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
		
		// Execute the prepared statement with the provided values
		$this->query($sql, array_values($data));
		
		// Return the ID of the last inserted row
		return $this->connection->insert_id;
	}
	
	/**
	 * Efficiently inserts multiple rows into a database table in a single query.
	 *
	 * This method dynamically builds a bulk INSERT query using prepared statements,
	 * reducing query execution time and improving database performance.
	 *
	 * Example:
	 * ```php
	 * $db->insertBatch('users', [
	 *     ['name' => 'Alice', 'age' => 25],
	 *     ['name' => 'Bob', 'age' => 30],
	 *     ['name' => 'Charlie', 'age' => 35]
	 * ]);
	 * ```
	 *
	 * @param string $table The database table name.
	 * @param array $data An array of associative arrays (each representing a row).
	 * @return int The number of inserted rows.
	 * @throws \mysqli_sql_exception If the query fails.
	 */
	public function insertBatch(string $table, array $data): int {
		if (empty($data)) {
			throw new \InvalidArgumentException("Data array cannot be empty.");
		}

		// Extract column names from the first row
		$columns = array_keys($data[0]);
		$columnList = '`' . implode('`, `', $columns) . '`';

		// Create placeholders for each row (e.g., "(?, ?, ?), (?, ?, ?), ...")
		$rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
		$placeholders = implode(', ', array_fill(0, count($data), $rowPlaceholder));

		// Flatten values into a single array for binding
		$values = [];
		foreach ($data as $row) {
			foreach ($columns as $col) {
				$values[] = $row[$col] ?? null; // Ensure NULL values are properly handled
			}
		}

		// Construct the bulk INSERT SQL query
		$sql = "INSERT INTO `$table` ($columnList) VALUES $placeholders";

		// Execute the query with parameter binding
		$this->query($sql, $values);

		return $this->connection->affected_rows;
	}
	

	/**
	 * Updates records in a database table.
	 *
	 * Constructs and executes an SQL UPDATE statement dynamically with bound parameters.
	 *
	 * @param string $table The name of the database table to update.
	 * @param array $data An associative array of column-value pairs to update (e.g., ['name' => 'John', 'age' => 30]).
	 * @param string $where The WHERE clause to specify which rows to update (e.g., "id = ?").
	 * @param array $params Additional parameters for the WHERE clause (e.g., [5] for "id = 5").
	 * @return int The number of affected rows.
	 * 
	 * @throws \mysqli_sql_exception If a database error occurs.
	 */
	public function update(string $table, array $data, string $where, array $params = []): int {
		
		// Construct the SET clause dynamically: "column1 = ?, column2 = ?"
		$set = implode(',', array_map(fn($col) => "$col = ?", array_keys($data)));
		
		// Build the full SQL query
		$sql = "UPDATE $table SET $set WHERE $where";
        
		// Execute the query, merging column values and WHERE parameters
		$this->query($sql, array_merge(array_values($data), $params));
		
		// Return the number of affected rows
		return $this->connection->affected_rows;
	}

	/**
	 * Deletes rows from a database table based on the given condition.
	 *
	 * This method executes a DELETE query using prepared statements to ensure security.
	 * The number of affected rows is returned after execution.
	 *
	 * @param string $table  The name of the database table.
	 * @param string $where  The WHERE clause specifying which rows to delete (e.g., "id = ?").
	 * @param array  $params Parameters to bind to the WHERE clause for prepared statements.
	 * @return int The number of affected rows after execution.
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function delete(string $table, string $where, array $params = []): int {
		
		// Construct the SQL DELETE query
		$sql = "DELETE FROM $table WHERE $where";
		
		// Execute the query with bound parameters (ensuring SQL safety)
		$this->query($sql, $params);
		
		// Return the number of rows affected by the deletion
		return $this->connection->affected_rows;
	}

    /**
     * Retrieves the ID of the last inserted row.
     *
     * This method returns the auto-generated ID from the last INSERT operation
     * performed on a table with an AUTO_INCREMENT column.
     *
     * @return int The ID of the last inserted row.
     *
     * @throws \mysqli_sql_exception If the database connection is lost.
     */
	public function lastInsertId(): int {
		// Returns the last inserted ID (which is automatically tracked by MySQLi)
		return $this->connection->insert_id;
	}

	/**
	 * Gets the number of rows affected by the last executed SQL statement.
	 *
	 * This method returns the number of rows that were modified, inserted, or deleted
	 * by the most recent query executed on this database connection.
	 *
	 * @return int The number of affected rows.
	 *
	 * @throws \mysqli_sql_exception If the connection is invalid or if an error occurs.
	 */
	public function affectedRows(): int {
		// Return the number of affected rows from the last executed SQL query
		return $this->connection->affected_rows;
	}

	/**
	 * Counts the number of rows from a given MySQL query or a `mysqli_result` object.
	 *
	 * If a `mysqli_result` object is passed, it directly returns the number of rows.
	 * If a raw SQL query is provided, it executes the query and returns the row count.
	 *
	 * @param \mysqli_result|string $resultOrSql Either a `mysqli_result` object or an SQL query string.
	 * @param array $params Optional parameters to bind if an SQL query is provided.
	 * @return int The number of rows in the result set.
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function countRows($resultOrSql, array $params = []): int {
		// If a `mysqli_result` object is provided, return its row count directly.
		if ($resultOrSql instanceof \mysqli_result) {
			return $resultOrSql->num_rows;
		}

		// Otherwise, execute the query and count the rows in the result set.
		$result = $this->query($resultOrSql, $params);
		
		// Retrieve the number of rows in the result set.
		$count = $result->num_rows;
		
		// Explicitly free the memory used by the result set to prevent leaks.
		$result->free();
		
		// Return the total number of rows found.
		return $count;
	}



	/**
	 * Checks whether a row exists in a specified database table based on a given condition.
	 *
	 * This method uses `SELECT EXISTS(SELECT 1 FROM table WHERE ... LIMIT 1)` to efficiently 
	 * check for the existence of a matching row. It avoids returning full result sets, 
	 * reducing memory usage and network overhead.
	 *
	 * @param string $table The name of the table to search in.
	 * @param string $where The WHERE clause (e.g., "column = ?"), defining the search condition.
	 * @param array $params Optional parameters to bind to the query placeholders.
	 * @return bool Returns `true` if at least one matching row exists, otherwise `false`.
	 *
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	/* 
	public function exists(string $table, string $where, array $params = []): bool {
		// Construct the EXISTS query to check for row existence efficiently
		$sql = "SELECT EXISTS(SELECT 1 FROM `$table` WHERE $where LIMIT 1)";

		// Execute the query with bound parameters
		$result = $this->query($sql, $params);

		// Fetch the first column (EXISTS result) and return true if it's 1, otherwise false
		return $result && $result->fetch_row()[0] == 1;
	}
	*/

	
	/**
	 * Checks whether a row exists in a specified database table based on a given condition.
	 *
	 * This method executes a `SELECT 1 ... LIMIT 1` query to determine if at least one row
	 * matches the provided WHERE condition. It is optimized for performance by only checking 
	 * for existence rather than retrieving full row data.
	 *
	 * @param string $table The name of the table to search in.
	 * @param string $where The WHERE clause (e.g., "column = ?"), defining the search condition.
	 * @param array $params Optional parameters to bind to the query placeholders.
	 * @return bool Returns `true` if at least one matching row exists, otherwise `false`.
	 *
	 * @throws \mysqli_sql_exception If the query execution fails.
	 */
	public function exists(string $table, string $where, array $params = []): bool {
		// Construct the EXISTS query safely
		$sql = "SELECT 1 FROM `$table` WHERE $where LIMIT 1";

		// Execute the query with the provided parameters.
		$result = $this->query($sql, $params);

		// Return true if at least one row was found, otherwise false.
		return $result && $result->num_rows > 0;
	}


	/**
	 * Starts a new database transaction.
	 *
	 * This method begins a transaction using MySQL's InnoDB engine.
	 * All subsequent queries will be part of this transaction until
	 * a commit or rollback is executed.
	 *
	 * @throws \mysqli_sql_exception If the transaction cannot be started.
	 */
	public function beginTransaction(): void {
		// Start a transaction
		$this->connection->begin_transaction();
	}

    /**
     * Commits the current database transaction.
     *
     * If a transaction is active, this method commits all changes made during the transaction.
     * If no transaction is active, calling this method has no effect.
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
     * the last `beginTransaction()` call.
     *
     * @throws \mysqli_sql_exception If the rollback operation fails.
     */
	public function rollback(): void {
		// Reverts uncommitted database changes
		$this->connection->rollback();
	}


	/**
	 * Executes a database transaction using a user-defined callback.
	 *
	 * This method starts a transaction, executes the provided callback function, 
	 * and commits the transaction if successful. If an exception occurs, 
	 * the transaction is rolled back and the exception is rethrown to be handled by the framework.
	 *
	 * **Example Usage:**
	 * ```php
	 * $db->easyTransaction(function($db) {
	 *     $db->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
	 *     $db->insert('orders', ['user_id' => $db->lastInsertId(), 'total' => 100]);
	 * });
	 * ```
	 *
	 * @param callable $callback A function that performs multiple queries within the transaction.
	 *                           The function receives the database instance (`$this`) as an argument.
	 *
	 * @throws \Throwable If any query fails, the transaction is rolled back and the exception is rethrown.
	 */
	public function easyTransaction(callable $callback): void {
		
		// Begin a new database transaction
		$this->connection->begin_transaction();
		
		try {
			// Execute the provided callback function, passing $this for database operations
			$callback($this);
			
			// Commit the transaction if no errors occurred
			$this->connection->commit();
			
		} catch (\Throwable $e) {
			
			// Rollback the transaction to prevent partial changes in case of failure
			$this->connection->rollback();
			
			// Rethrow the exception so the framework's error handler can log and manage it
			throw $e;
		}
	}


	/**
	 * Binds parameters to a prepared MySQLi statement using a simplistic approach.
	 *
	 * Limitations of this method:
	 * - All parameters are treated as strings (`s`), which may lead to unexpected behavior for integers (`i`) or floats (`d`).
	 * - No type detection – MySQL may perform implicit type conversions, which can affect query performance.
	 * - Not suitable for binary data (`b`) or handling complex data types.
	 *
	 * When to use this method:
	 * - When ALL bound parameters are strings.
	 * - When performance is more important than strict type safety.
	 * - For quick-and-dirty prototyping, not for production-critical queries.
	 *
	 * @param \mysqli_stmt $stmt   The prepared statement to bind parameters to.
	 * @param array        $params The parameters to bind, passed as an indexed array.
	 *
	 * @throws \mysqli_sql_exception If binding fails due to mismatched parameter count.
	 */
	private function bindParamsDownAndDirty(\mysqli_stmt $stmt, array $params): void {
		if (empty($params)) return;

		$types = str_repeat('s', count($params));
		$stmt->bind_param($types, ...$params);
	}
	 
	/**
	 * Binds parameters to a prepared MySQLi statement.
	 *
	 * MySQLi requires explicit type definitions for each parameter when using `bind_param()`.  
	 * This method dynamically determines the correct type for each parameter and binds them  
	 * to the prepared statement before execution.
	 *
	 * Type Mapping:
	 * - `i` → Integer (`int`, including `true`/`false` converted to `1`/`0`)
	 * - `d` → Double (`float`)
	 * - `s` → String (`string`, including `NULL`, as MySQLi treats NULL as a string internally)
     *  
     * If the parameter type is unknown, it is cast to a string (`s`).
     *
	 * Optimized Handling of NULL Values:
	 * - MySQLi does not have a separate `NULL` type, so `NULL` is bound as a string (`s`).
	 * - Ensures `NULL` values are not mistakenly converted to `0` when binding integers.
	 *
	 * @param \mysqli_stmt $stmt   The prepared statement to bind parameters to.
	 * @param array        $params The parameters to bind, in sequential order.
	 *
	 * @throws \mysqli_sql_exception If binding fails due to invalid types or statement errors.
	 */
	 
	private function bindParams(\mysqli_stmt $stmt, array $params): void {
		
		// If no parameters are provided, there is nothing to bind.		
		if (empty($params)) return;
	
		// String representing the parameter types for bind_param()
		$types = '';
		
		// Array to hold references to parameters for bind_param()
		$paramsRef = [];


		// Loop through each parameter and determine its corresponding MySQLi type
		// Use reference (&) to modify the actual array values if needed (e.g., convert booleans to integers).
		foreach ($params as &$param) {
			if (is_null($param)) {
				$types .= 's'; // Use 's' (string) for NULL to prevent MySQL from converting INT NULL to 0
				$param = null; // Ensures NULL values remain unchanged
			} elseif (is_string($param)) {
				$types .= 's'; // String
			} elseif (is_int($param)) {
				$types .= 'i'; // Integer
			} elseif (is_float($param)) {
				$types .= 'd'; // Double/Float
			} elseif (is_bool($param)) {
				$param = $param ? 1 : 0; // Convert boolean to integer (1 for true, 0 for false)
				$types .= 'i';		
			} else {
				$param = (string) $param; // Fallback to string
				$types .= 's';
			}
			
			// Add parameter reference to array (required for bind_param)
			$paramsRef[] = &$param;
		}

		// Prepend the type string to the parameters array
		array_unshift($paramsRef, $types);
		
		// Bind parameters dynamically to the prepared statement
		call_user_func_array([$stmt, 'bind_param'], $paramsRef);
		
		
		/* 		
		This somewhat simpler version was abandoned because it converted INT NULL to 0...
		
		foreach ($params as &$param) {
			if (is_string($param)) {
				$types .= 's'; // Strings and NULL
			} elseif (is_int($param)) {
				$types .= 'i'; // Integer
			} elseif (is_float($param)) {
				$types .= 'd'; // Float/Double
			} elseif (is_bool($param)) {
				$param = $param ? 1 : 0; // Convert boolean to integer (1 for true, 0 for false)
				$types .= 'i';		
			} else {
				$param = (string) $param; // Fallback to string
				$types .= 's';
			}
		}		
		
		// Bind parameters to the prepared statement.
		$stmt->bind_param($types, ...$params);
		*/
		
	}
	

	/**
	 * Retrieves a prepared statement from cache or creates a new one.
	 *
	 * This method checks if a prepared statement for the given SQL query
	 * already exists in the cache. If it does, it returns the cached statement.
	 * Otherwise, it prepares a new statement, caches it, and returns it.
	 *
	 * If the statement preparation fails, an exception is thrown.
	 *
	 * @param string $sql The SQL query to prepare.
	 * @return \mysqli_stmt The prepared statement.
	 * @throws \mysqli_sql_exception If statement preparation fails.
	 */
	private function getPreparedStatement(string $sql): \mysqli_stmt {

		// Check if the statement is already cached to avoid redundant preparation
		if (!isset($this->statementCache[$sql])) {
			
			// Prepare and cache the statement if not already cached
			// $this->statementCache[$sql] = $this->connection->prepare($sql);
			
			// Prepare a new statement using MySQLi
			$stmt = $this->connection->prepare($sql);
			
			// If preparation fails, throw an exception with the SQL query and error message
			if (!$stmt) {
				throw new \mysqli_sql_exception("Failed to prepare statement: {$sql} - Error: " . $this->connection->error);
			}
			
			// Store the successfully prepared statement in cache
			$this->statementCache[$sql] = $stmt;
			
		}

		// Return the cached or newly prepared statement
		return $this->statementCache[$sql];
	}
		
	/**
	 * Returns the last MySQL error message.
	 *
	 * @return string|null The error message or null if no error occurred.
	 */
	public function getLastError(): ?string {
		return $this->connection->error ?: null;
	}
	
	/**
	 * Returns the last MySQL error code.
	 *
	 * @return int The error code (0 if no error).
	 */
	public function getLastErrorCode(): int {
		return $this->connection->errno;
	}		

	/**
	 * Destructor to clean up database resources.
	 *
	 * Closes all cached prepared statements and the database connection 
	 * when the object is destroyed.
	 *
	 * @throws \mysqli_sql_exception If closing the statements or connection fails.
	 */
	public function __destruct() {
		
		// Close all prepared statements in the cache
		foreach ($this->statementCache as $stmt) {
			$stmt->close();
		}
		
		// Close the database connection
		$this->connection->close();
	}
}

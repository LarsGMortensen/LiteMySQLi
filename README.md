# LiteMySQLi - Lightweight MySQLi Wrapper for PHP
LiteMySQLi is a lightweight, efficient MySQLi wrapper designed to simplify database interactions in PHP. It offers a clean, intuitive API for executing queries, handling transactions, and optimizing performance with prepared statements and caching.

## Features
✅ Easy-to-use, minimalistic API  
✅ Secure prepared statements with automatic binding  
✅ Efficient caching of prepared statements  
✅ Transaction support with commit/rollback  
✅ Bulk insert operations for high performance  
✅ Supports MySQLi error reporting and debugging  
✅ Ultra-fast performance for database operations  

## Installation
Simply include the `LiteMySQLi.php` file in your project:

```php
require_once 'LiteMySQLi.php';
```

## Usage
### Connect to the Database
```php
$db = new LiteMySQLi('localhost', 'username', 'password', 'database');
```

### Fetch a single row
```php
$user = $db->fetchRow("SELECT * FROM users WHERE email = ?", ['john@example.com']);
```

### Fetch all rows
```php
$users = $db->fetchAll("SELECT * FROM users");
```

### Insert data
```php
$lastId = $db->insert('users', ['name' => 'John Doe', 'email' => 'john@example.com']);
```

### Bulk insert
```php
$data = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com']
];
$db->insertBatch('users', $data);
```

### Update data
```php
$db->update('users', ['name' => 'Jane Doe'], 'email = ?', ['john@example.com']);
```

### Delete data
```php
$db->delete('users', 'email = ?', ['john@example.com']);
```

### Check if a record exists
```php
$exists = $db->exists("SELECT 1 FROM users WHERE email = ?", ['john@example.com']);
```

### Count rows in a query
```php
$count = $db->countRows("SELECT * FROM users");
```

### Get number of affected rows
```php
$affected = $db->affectedRows();
```

### Get last inserted ID
```php
$lastId = $db->lastInsertId();
```

### Transactions
```php
$db->beginTransaction();
try {
    $db->insert('orders', ['user_id' => 1, 'total' => 99.99]);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

### Easy transaction handling (with automatic rollback)
```php
$db->easyTransaction(function ($db) {
    $db->insert('users', ['name' => 'Alice', 'email' => 'alice@example.com']);
});
```
If an exception occurs inside the callback, the transaction will **automatically rollback** to prevent partial inserts.

### Easy transaction handling with bulk insert
```php
$db->easyTransaction(function ($db) {
    $data = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
        ['name' => 'Charlie', 'email' => 'charlie@example.com']
    ];
    foreach ($data as $row) {
        $db->insert('users', $row);
    }
});
```
This ensures that either **all rows are inserted successfully or none at all** in case of an error.

## Error handling
LiteMySQLi uses MySQLi's strict error reporting mode to throw exceptions on errors. Use try-catch blocks for better error management:
```php
try {
    $db->execute("INVALID SQL QUERY");
} catch (mysqli_sql_exception $e) {
    echo "Database error: " . $e->getMessage();
}
```

## License
LiteMySQLi is released under the **GNU General Public License v3.0**. See [LICENSE](LICENSE) for details.

## Contributing
Contributions are welcome! Feel free to fork this repository, submit issues, or open a pull request.

## Author
Developed by **Lars Grove Mortensen** © 2025. Feel free to reach out or contribute!

---

🌟 **If you find this library useful, give it a star on GitHub!** 🌟


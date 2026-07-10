<?php
/**
 * One-time helper: creates the database `certreefy_db` and the
 * `tbl_users` table used by the application.
 *
 * Usage:
 * - Run from the browser or CLI once, then delete this file.
 * - It uses default credentials matching connection/config.php.
 */

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dbName = 'certreefy_db';

$dsn = "mysql:host={$host};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Create the database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `{$dbName}` created or already exists.<br>\n";

    // Switch to the new database
    $pdo->exec("USE `{$dbName}`");

    // Create users table
    $createTable = <<<SQL
CREATE TABLE IF NOT EXISTS `tbl_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(100) NOT NULL,
  `mname` VARCHAR(100) DEFAULT NULL,
  `lname` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `contact` VARCHAR(20) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin','community','greenhouse') NOT NULL DEFAULT 'community',
  `status` ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($createTable);
    echo "Table `tbl_users` created or already exists.<br>\n";

    echo "<strong>Done.</strong> Please delete this file (`create_database_and_tables.php`) after successful execution.";

} catch (PDOException $e) {
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit(1);
}

?>

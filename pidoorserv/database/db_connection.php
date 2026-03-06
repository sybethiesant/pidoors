<?php
/**
 * Database Connection with PDO for secure prepared statements
 * PiDoors Access Control System
 */

$config = include(__DIR__ . '/../includes/config.php');

try {
    $pdo_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (!empty($config['sql_ssl_ca']) && file_exists($config['sql_ssl_ca'])) {
        $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = $config['sql_ssl_ca'];
        $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    // Create PDO connection for main users database
    $pdo = new PDO(
        "mysql:host={$config['sqladdr']};dbname={$config['sqldb']};charset=utf8mb4",
        $config['sqluser'],
        $config['sqlpass'],
        $pdo_options
    );

    // Create PDO connection for access database
    $pdo_access = new PDO(
        "mysql:host={$config['sqladdr']};dbname={$config['sqldb2']};charset=utf8mb4",
        $config['sqluser'],
        $config['sqlpass'],
        $pdo_options
    );

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact the administrator.");
}

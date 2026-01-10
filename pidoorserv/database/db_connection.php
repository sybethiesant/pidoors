<?php
/**
 * Database Connection with PDO for secure prepared statements
 * PiDoors Access Control System
 */

$config = include(__DIR__ . '/../includes/config.php');

try {
    // Create PDO connection for main users database
    $pdo = new PDO(
        "mysql:host={$config['sqladdr']};dbname={$config['sqldb']};charset=utf8mb4",
        $config['sqluser'],
        $config['sqlpass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Create PDO connection for access database
    $pdo_access = new PDO(
        "mysql:host={$config['sqladdr']};dbname={$config['sqldb2']};charset=utf8mb4",
        $config['sqluser'],
        $config['sqlpass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact the administrator.");
}

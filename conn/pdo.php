<?php
require_once __DIR__ . '/../config/env.php';

$host = (string) bidmap_env('DB_HOST', '127.0.0.1');
$user = (string) bidmap_env('DB_USER', '');
$password = (string) bidmap_env('DB_PASSWORD', '');
$database = (string) bidmap_env('DB_DATABASE', '');
$port = (int) bidmap_env('DB_PORT', '3306');
$connectTimeout = max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5'));

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => $connectTimeout,
]);
?>

<?php
require_once __DIR__ . '/../config/env.php';

$host = (string) bidmap_env('DB_HOST', '127.0.0.1');
$user = (string) bidmap_env('DB_USER', '');
$password = (string) bidmap_env('DB_PASSWORD', '');
$database = (string) bidmap_env('DB_DATABASE', '');
$port = (int) bidmap_env('DB_PORT', '3306');
$connectTimeout = max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5'));

$conexao = mysqli_init();
$conexao->options(MYSQLI_OPT_CONNECT_TIMEOUT, $connectTimeout);
$conexao->real_connect($host, $user, $password, $database, $port);

if ($conexao->connect_error) {
    die("Erro na conexão: " . $conexao->connect_error);
}

$conexao->set_charset("utf8mb4");
?>

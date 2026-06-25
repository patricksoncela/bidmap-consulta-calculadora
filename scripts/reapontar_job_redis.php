<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';
require_once __DIR__ . '/../conn/conexao.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$idJob = isset($argv[1]) ? (int) $argv[1] : 0;
$fila = trim((string) ($argv[2] ?? ''));

if ($idJob <= 0 || $fila === '') {
    fwrite(STDERR, "Uso: php scripts/reapontar_job_redis.php ID_JOB FILA_REDIS\n");
    $conexao->close();
    exit(1);
}

$jobs = consulta_table('jobs');
$status = 'pending';
$stmt = $conexao->prepare(
    "UPDATE {$jobs}
     SET fila_redis = ?,
         redis_enqueued_at = NULL,
         worker_id = NULL,
         updated_at = NOW()
     WHERE id_job = ?
       AND status_job = ?"
);

if (!$stmt) {
    fwrite(STDERR, 'Erro ao preparar SQL: ' . $conexao->error . PHP_EOL);
    $conexao->close();
    exit(1);
}

$stmt->bind_param('sis', $fila, $idJob, $status);
$stmt->execute();

if ($stmt->errno !== 0) {
    fwrite(STDERR, 'Erro ao executar SQL: ' . $stmt->error . PHP_EOL);
    $stmt->close();
    $conexao->close();
    exit(1);
}

echo json_encode([
    'ok' => true,
    'id_job' => $idJob,
    'fila_redis' => $fila,
    'linhas_afetadas' => $stmt->affected_rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

$stmt->close();
$conexao->close();

<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$limit = max(1, min(50, (int) ($argv[1] ?? 15)));

function diag_db(): mysqli
{
    $mysqli = mysqli_init();
    $timeout = max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5'));
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);

    $connected = $mysqli->real_connect(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev'),
        (int) bidmap_env('DB_PORT', '3306')
    );

    if (!$connected) {
        throw new RuntimeException('Erro ao conectar no banco: ' . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');

    return $mysqli;
}

function diag_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array
{
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar diagnostico: ' . $mysqli->error);
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

try {
    $mysqli = diag_db();
    $jobs = consulta_table('jobs');
    $pedidos = consulta_table('pedidos_usuarios');

    $status = diag_rows(
        $mysqli,
        "SELECT status_job, COUNT(*) AS total
         FROM {$jobs}
         GROUP BY status_job
         ORDER BY status_job"
    );

    $recentes = diag_rows(
        $mysqli,
        "SELECT j.id_job, j.tipo_job, j.fila_redis, j.status_job, j.id_consulta_usuario,
                j.id_consulta, j.tentativas, j.max_tentativas, j.redis_enqueued_at,
                j.available_at, j.next_retry_at, j.last_error, j.created_at, j.updated_at,
                p.status_resultado AS pedido_status, p.entrada_original
         FROM {$jobs} j
         LEFT JOIN {$pedidos} p ON p.id_consulta_usuario = j.id_consulta_usuario
         ORDER BY j.id_job DESC
         LIMIT ?",
        'i',
        [$limit]
    );

    $pendentes = diag_rows(
        $mysqli,
        "SELECT j.id_job, j.tipo_job, j.fila_redis, j.status_job, j.id_consulta_usuario,
                j.id_consulta, j.tentativas, j.max_tentativas, j.redis_enqueued_at,
                j.available_at, j.next_retry_at, j.last_error, j.created_at, j.updated_at,
                p.status_resultado AS pedido_status, p.entrada_original
         FROM {$jobs} j
         LEFT JOIN {$pedidos} p ON p.id_consulta_usuario = j.id_consulta_usuario
         WHERE j.status_job IN ('pending', 'processing', 'failed')
         ORDER BY j.created_at ASC
         LIMIT ?",
        'i',
        [$limit]
    );

    $mysqli->close();

    echo json_encode([
        'ok' => true,
        'gerado_em' => date('c'),
        'status' => $status,
        'pendentes_processando_erro' => $pendentes,
        'recentes' => $recentes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

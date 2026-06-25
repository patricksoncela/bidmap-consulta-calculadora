<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1)), static fn (int $id): bool => $id > 0));

if ($ids === []) {
    fwrite(STDERR, 'Informe um ou mais IDs de pedido.' . PHP_EOL);
    exit(1);
}

function relatorio_db(): mysqli
{
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5')));
    $ok = $mysqli->real_connect(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev'),
        (int) bidmap_env('DB_PORT', '3306')
    );

    if (!$ok) {
        throw new RuntimeException('Erro ao conectar no banco: ' . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function relatorio_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array
{
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar relatorio: ' . $mysqli->error);
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

$mysqli = relatorio_db();
$pedidos = consulta_table('pedidos_usuarios');
$jobs = consulta_table('jobs');
$externo = consulta_table('controle_credito_externo');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$rows = relatorio_rows(
    $mysqli,
    "SELECT p.id_consulta_usuario, p.id_usuario, p.modalidade_pedido, p.entrada_original,
            p.entrada_normalizada, p.status_resultado, p.id_consulta, p.data_pedido,
            p.creditos_usuario_consumidos, p.saldo_anterior_usuario, p.saldo_posterior_usuario,
            p.credito_transacao_id, p.credito_estorno_transacao_id, p.credito_externo_consumido,
            p.mensagem_resultado,
            j.id_job, j.tipo_job, j.fila_redis, j.status_job, j.tentativas, j.max_tentativas,
            j.last_error, j.created_at AS job_created_at, j.updated_at AS job_updated_at,
            COALESCE(ext.total_debitos, 0) AS total_debitos_externos,
            COALESCE(ext.creditos_externos, 0) AS creditos_externos_registrados
     FROM {$pedidos} p
     LEFT JOIN {$jobs} j ON j.id_consulta_usuario = p.id_consulta_usuario
     LEFT JOIN (
        SELECT id_consulta, COUNT(*) AS total_debitos, SUM(creditos_movimentados) AS creditos_externos
        FROM {$externo}
        WHERE operacao = 'debito'
        GROUP BY id_consulta
     ) ext ON ext.id_consulta = p.id_consulta
     WHERE p.id_consulta_usuario IN ({$placeholders})
     ORDER BY p.id_consulta_usuario",
    $types,
    $ids
);

$mysqli->close();

echo json_encode([
    'ok' => true,
    'gerado_em' => date('c'),
    'pedidos' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

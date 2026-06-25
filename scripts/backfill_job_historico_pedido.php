<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../services/ConsultaJobService.php';
require_once __DIR__ . '/../database/models/ConsultaJobModel.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1)), static fn (int $id): bool => $id > 0));

if ($ids === []) {
    fwrite(STDERR, 'Informe um ou mais IDs de pedido para backfill.' . PHP_EOL);
    exit(1);
}

function backfill_db(): mysqli
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

$mysqli = backfill_db();
$pedidoModel = new PedidoUsuarioModel($mysqli);
$jobService = new ConsultaJobService($mysqli);
$jobModel = new ConsultaJobModel($mysqli);
$resultados = [];

foreach ($ids as $pedidoId) {
    $pedido = $pedidoModel->buscarPorId($pedidoId);

    if (!$pedido) {
        $resultados[] = ['pedido_id' => $pedidoId, 'ok' => false, 'message' => 'Pedido nao encontrado.'];
        continue;
    }

    $consultaId = !empty($pedido['id_consulta']) ? (int) $pedido['id_consulta'] : null;
    $tipoJob = (string) ($pedido['modalidade_pedido'] ?? '');

    if ($tipoJob === '' || $consultaId === null) {
        $resultados[] = ['pedido_id' => $pedidoId, 'ok' => false, 'message' => 'Pedido sem modalidade ou consulta.'];
        continue;
    }

    $jobId = $jobService->registrar($tipoJob, $consultaId, $pedidoId, [
        'fornecedor' => (string) ($pedido['fornecedor'] ?? ''),
        'modalidade_pedido' => $tipoJob,
        'entrada_original' => (string) ($pedido['entrada_original'] ?? ''),
        'entrada_normalizada' => (string) ($pedido['entrada_normalizada'] ?? ''),
        'backfill' => true,
        'backfill_motivo' => 'Pedido historico processado antes do Redis worker.',
    ]);

    if (!$jobId) {
        $resultados[] = ['pedido_id' => $pedidoId, 'ok' => false, 'message' => 'Nao foi possivel criar job.'];
        continue;
    }

    $jobModel->marcarConcluido($jobId, [
        'backfill' => true,
        'pedido_id' => $pedidoId,
        'consulta_id' => $consultaId,
        'message' => 'Job historico criado para auditoria.',
    ]);

    $resultados[] = [
        'pedido_id' => $pedidoId,
        'ok' => true,
        'job_id' => $jobId,
        'consulta_id' => $consultaId,
    ];
}

$mysqli->close();

echo json_encode([
    'ok' => !array_filter($resultados, static fn (array $row): bool => ($row['ok'] ?? false) !== true),
    'gerado_em' => date('c'),
    'resultados' => $resultados,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

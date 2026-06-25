<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';
require_once __DIR__ . '/../services/ProcessosCleanupService.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);

function manutencao_db(): mysqli
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

function manutencao_scalar(mysqli $mysqli, string $sql): int
{
    $result = $mysqli->query($sql);

    if (!$result) {
        throw new RuntimeException('Erro na consulta de manutencao: ' . $mysqli->error);
    }

    $row = $result->fetch_assoc() ?: [];
    $result->free();

    return (int) reset($row);
}

try {
    $mysqli = manutencao_db();
    $jobs = consulta_table('jobs');
    $pedidos = consulta_table('pedidos_usuarios');
    $consultas = consulta_table('consultas_externas');

    $cleanup = new ProcessosCleanupService($mysqli);
    $cleanupResult = $cleanup->executar(!$apply);

    $statusJobs = [];
    $result = $mysqli->query(
        "SELECT status_job, COUNT(*) AS total
         FROM {$jobs}
         GROUP BY status_job
         ORDER BY status_job"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $statusJobs[(string) $row['status_job']] = (int) $row['total'];
        }
        $result->free();
    }

    $relatorio = [
        'ok' => true,
        'modo' => $apply ? 'apply' : 'dry-run',
        'gerado_em' => date('c'),
        'jobs_por_status' => $statusJobs,
        'jobs_processing_antigos' => manutencao_scalar(
            $mysqli,
            "SELECT COUNT(*)
             FROM {$jobs}
             WHERE status_job = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        ),
        'jobs_pending_antigos' => manutencao_scalar(
            $mysqli,
            "SELECT COUNT(*)
             FROM {$jobs}
             WHERE status_job = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        ),
        'pedidos_pendentes_antigos' => manutencao_scalar(
            $mysqli,
            "SELECT COUNT(*)
             FROM {$pedidos}
             WHERE status_resultado = 'pendente'
               AND data_pedido < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        ),
        'consultas_pendentes_antigas' => manutencao_scalar(
            $mysqli,
            "SELECT COUNT(*)
             FROM {$consultas}
             WHERE status_resultado = 'pendente'
               AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        ),
        'limpeza' => $cleanupResult,
    ];

    $mysqli->close();

    if ($json) {
        echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    echo 'Manutencao de consultas' . PHP_EOL;
    echo 'Modo: ' . $relatorio['modo'] . PHP_EOL;
    echo 'Gerado em: ' . $relatorio['gerado_em'] . PHP_EOL;
    echo PHP_EOL . 'Jobs por status:' . PHP_EOL;

    foreach ($relatorio['jobs_por_status'] as $status => $total) {
        echo '- ' . $status . ': ' . $total . PHP_EOL;
    }

    echo PHP_EOL . 'Pendencias:' . PHP_EOL;
    echo '- jobs processing antigos: ' . $relatorio['jobs_processing_antigos'] . PHP_EOL;
    echo '- jobs pending antigos: ' . $relatorio['jobs_pending_antigos'] . PHP_EOL;
    echo '- pedidos pendentes antigos: ' . $relatorio['pedidos_pendentes_antigos'] . PHP_EOL;
    echo '- consultas pendentes antigas: ' . $relatorio['consultas_pendentes_antigas'] . PHP_EOL;

    echo PHP_EOL . 'Limpeza:' . PHP_EOL;
    foreach ($relatorio['limpeza'] as $key => $value) {
        if (is_array($value)) {
            echo '- ' . $key . ': ' . count($value) . PHP_EOL;
            continue;
        }
        echo '- ' . $key . ': ' . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . PHP_EOL;
    }

    if (!$apply) {
        echo PHP_EOL . 'Dry-run: nenhuma alteracao foi aplicada. Use --apply para executar a limpeza.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($json) {
        echo json_encode([
            'ok' => false,
            'erro' => $exception->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}

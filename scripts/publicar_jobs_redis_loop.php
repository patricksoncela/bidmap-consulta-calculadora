<?php

require_once __DIR__ . '/../config/env.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

require_once __DIR__ . '/../services/RedisJobPublisherService.php';

$limitePorCiclo = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 100;
$intervaloSegundos = isset($argv[2]) ? max(1, (int) $argv[2]) : 3;
$logIdleEvery = max(10, (int) bidmap_env('REDIS_PUBLISHER_LOG_IDLE_EVERY', '60'));
$maxCycles = max(0, (int) bidmap_env('REDIS_PUBLISHER_MAX_CYCLES', '1200'));
$maxRuntimeSeconds = max(0, (int) bidmap_env('REDIS_PUBLISHER_MAX_RUNTIME_SECONDS', '3600'));

function publisher_redis_db(): mysqli
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
        throw new RuntimeException('Erro na conexao com o banco: ' . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');

    return $mysqli;
}

function publisher_redis_db_alive(mysqli $mysqli): bool
{
    try {
        $result = @$mysqli->query('SELECT 1');

        if ($result instanceof mysqli_result) {
            $result->free();
            return true;
        }

        return $result === true;
    } catch (Throwable $exception) {
        return false;
    }
}

function publisher_log_resultado(array $resultado, bool $logIdle): void
{
    foreach ($resultado['jobs'] ?? [] as $job) {
        $linha = '[' . date('c') . '] job=' . (int) $job['id_job'] .
            ' fila=' . (string) $job['fila_redis'] .
            ' status=' . (string) $job['status'];

        if (!empty($job['erro'])) {
            $linha .= ' erro=' . (string) $job['erro'];
        }

        echo $linha . PHP_EOL;
    }

    if ($logIdle || (int) ($resultado['publicados'] ?? 0) > 0 || (int) ($resultado['falhas'] ?? 0) > 0 || (int) ($resultado['recuperados'] ?? 0) > 0) {
        echo '[' . date('c') . '] recuperados=' . (int) ($resultado['recuperados'] ?? 0) .
            ' reservados=' . (int) ($resultado['reservados'] ?? 0) .
            ' publicados=' . (int) ($resultado['publicados'] ?? 0) .
            ' falhas=' . (int) ($resultado['falhas'] ?? 0) .
            PHP_EOL;
    }
}

echo '[' . date('c') . "] Loop do publisher Redis iniciado. limite={$limitePorCiclo} intervalo={$intervaloSegundos}s max_cycles={$maxCycles} max_runtime={$maxRuntimeSeconds}s" . PHP_EOL;

$mysqli = null;
$publisher = null;
$lastIdleLog = 0;
$startedAt = time();
$cycles = 0;

while (true) {
    try {
        if ($maxRuntimeSeconds > 0 && time() - $startedAt >= $maxRuntimeSeconds) {
            echo '[' . date('c') . "] encerrando_publisher=limite_tempo ciclos={$cycles}" . PHP_EOL;
            break;
        }

        if ($maxCycles > 0 && $cycles >= $maxCycles) {
            echo '[' . date('c') . "] encerrando_publisher=limite_ciclos ciclos={$cycles}" . PHP_EOL;
            break;
        }

        if (!$mysqli instanceof mysqli) {
            $mysqli = publisher_redis_db();
            $publisher = new RedisJobPublisherService($mysqli);
        }

        if (!publisher_redis_db_alive($mysqli)) {
            echo '[' . date('c') . '] reconectando_banco=conexao_inativa' . PHP_EOL;
            @$mysqli->close();
            $mysqli = null;
            $publisher = null;
            continue;
        }

        $resultado = $publisher->publicarPendentes($limitePorCiclo);
        $cycles++;
        $now = time();
        $logIdle = $now - $lastIdleLog >= $logIdleEvery;
        publisher_log_resultado($resultado, $logIdle);

        if ($logIdle) {
            $lastIdleLog = $now;
        }
    } catch (Throwable $exception) {
        echo '[' . date('c') . '] erro_publisher=' . $exception->getMessage() . PHP_EOL;

        if ($mysqli instanceof mysqli) {
            @$mysqli->close();
        }

        $mysqli = null;
        $publisher = null;
    }

    sleep($intervaloSegundos);
}

if ($mysqli instanceof mysqli) {
    @$mysqli->close();
}

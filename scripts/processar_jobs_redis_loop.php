<?php

require_once __DIR__ . '/../config/env.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

require_once __DIR__ . '/../services/RedisJobWorkerService.php';

$timeout = isset($argv[1]) ? max(1, (int) $argv[1]) : 5;
$logIdleEvery = max(10, (int) bidmap_env('REDIS_WORKER_LOG_IDLE_EVERY', '60'));
$maxJobs = max(0, (int) bidmap_env('REDIS_WORKER_MAX_JOBS', '500'));
$maxRuntimeSeconds = max(0, (int) bidmap_env('REDIS_WORKER_MAX_RUNTIME_SECONDS', '3600'));

function redis_worker_db(): mysqli
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

function redis_worker_db_alive(mysqli $mysqli): bool
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

echo '[' . date('c') . "] Loop do worker Redis iniciado. timeout={$timeout}s max_jobs={$maxJobs} max_runtime={$maxRuntimeSeconds}s" . PHP_EOL;

$mysqli = null;
$worker = null;
$lastIdleLog = 0;
$startedAt = time();
$processedJobs = 0;

while (true) {
    try {
        if ($maxRuntimeSeconds > 0 && time() - $startedAt >= $maxRuntimeSeconds) {
            echo '[' . date('c') . "] encerrando_worker=limite_tempo processados={$processedJobs}" . PHP_EOL;
            break;
        }

        if ($maxJobs > 0 && $processedJobs >= $maxJobs) {
            echo '[' . date('c') . "] encerrando_worker=limite_jobs processados={$processedJobs}" . PHP_EOL;
            break;
        }

        if (!$mysqli instanceof mysqli) {
            $mysqli = redis_worker_db();
            $worker = new RedisJobWorkerService($mysqli);
        }

        if (!redis_worker_db_alive($mysqli)) {
            echo '[' . date('c') . '] reconectando_banco=conexao_inativa' . PHP_EOL;
            @$mysqli->close();
            $mysqli = null;
            $worker = null;
            continue;
        }

        $resultado = $worker->processarProximo($timeout);

        if (($resultado['processed'] ?? false) === true) {
            $processedJobs++;
            $idJob = (int) ($resultado['id_job'] ?? 0);
            $resultadoItem = is_array($resultado['result'] ?? null) ? $resultado['result'] : [];
            $status = ($resultadoItem['pending'] ?? false) ? 'pendente' : (($resultadoItem['ok'] ?? false) ? 'ok' : 'erro');
            $mensagem = (string) ($resultadoItem['message'] ?? $resultado['message'] ?? '');
            echo '[' . date('c') . "] job={$idJob} status={$status} {$mensagem}" . PHP_EOL;
            continue;
        }

        $now = time();
        if ($now - $lastIdleLog >= $logIdleEvery) {
            echo '[' . date('c') . '] Redis sem jobs no intervalo.' . PHP_EOL;
            $lastIdleLog = $now;
        }
    } catch (Throwable $exception) {
        echo '[' . date('c') . '] erro_worker_redis=' . $exception->getMessage() . PHP_EOL;

        if ($mysqli instanceof mysqli) {
            @$mysqli->close();
        }

        $mysqli = null;
        $worker = null;
        sleep(3);
    }
}

if ($mysqli instanceof mysqli) {
    @$mysqli->close();
}

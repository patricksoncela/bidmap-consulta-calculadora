<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/RedisQueueClient.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$modo = (string) ($argv[1] ?? 'worker');
$checks = [
    'modo' => $modo,
    'php' => PHP_VERSION,
    'ok' => true,
    'checks' => [],
];

function health_fail(array &$checks, string $name, string $message): void
{
    $checks['ok'] = false;
    $checks['checks'][$name] = [
        'ok' => false,
        'message' => $message,
    ];
}

function health_ok(array &$checks, string $name, array $extra = []): void
{
    $checks['checks'][$name] = array_merge(['ok' => true], $extra);
}

try {
    foreach (['mysqli', 'curl'] as $extension) {
        if (!extension_loaded($extension)) {
            health_fail($checks, 'ext_' . $extension, 'Extensao PHP ausente: ' . $extension);
        } else {
            health_ok($checks, 'ext_' . $extension);
        }
    }

    $redis = new RedisQueueClient();
    if ($redis->ping()) {
        health_ok($checks, 'redis');
    } else {
        health_fail($checks, 'redis', 'PING do Redis nao retornou PONG.');
    }
    $redis->close();
} catch (Throwable $exception) {
    health_fail($checks, 'redis', $exception->getMessage());
}

try {
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
        health_fail($checks, 'db', mysqli_connect_error() ?: 'Falha ao conectar no banco.');
    } else {
        $mysqli->set_charset('utf8mb4');
        $result = $mysqli->query('SELECT 1 AS ok');

        if ($result instanceof mysqli_result) {
            $result->free();
            health_ok($checks, 'db', ['connect_timeout' => $timeout]);
        } else {
            health_fail($checks, 'db', $mysqli->error ?: 'SELECT 1 falhou.');
        }

        $mysqli->close();
    }
} catch (Throwable $exception) {
    health_fail($checks, 'db', $exception->getMessage());
}

$storageDir = dirname(__DIR__) . '/storage';
if (is_dir($storageDir) && is_writable($storageDir)) {
    health_ok($checks, 'storage', ['path' => $storageDir]);
} else {
    health_fail($checks, 'storage', 'Storage ausente ou sem permissao de escrita: ' . $storageDir);
}

$pdfStorageEnv = trim((string) bidmap_env('PROCESSO_RAPIDO_STORAGE_DIR', 'storage/processos_pdf'));
$pdfStorageDir = $pdfStorageEnv;

if ($pdfStorageDir === '') {
    $pdfStorageDir = 'storage/processos_pdf';
}

if (!preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $pdfStorageDir)) {
    $pdfStorageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($pdfStorageDir, '/\\');
}

if (is_dir($pdfStorageDir) && is_writable($pdfStorageDir)) {
    health_ok($checks, 'pdf_storage', [
        'env' => $pdfStorageEnv,
        'path' => $pdfStorageDir,
    ]);
} else {
    health_fail($checks, 'pdf_storage', 'Storage de PDFs ausente ou sem permissao de escrita: ' . $pdfStorageDir);
}

echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($checks['ok'] ? 0 : 1);

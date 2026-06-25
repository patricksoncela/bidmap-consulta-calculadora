<?php

require_once __DIR__ . '/../config/env.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$extensoesObrigatorias = ['mysqli', 'curl', 'json'];
$extensoesFaltando = array_values(array_filter(
    $extensoesObrigatorias,
    static fn(string $extensao): bool => !extension_loaded($extensao)
));

if ($extensoesFaltando !== []) {
    fwrite(
        STDERR,
        'Extensoes obrigatorias nao carregadas no PHP CLI: ' .
        implode(', ', $extensoesFaltando) .
        '. Execute este worker com o mesmo PHP configurado do servidor ou habilite as extensoes no php.ini.' .
        PHP_EOL
    );
    exit(1);
}

require_once __DIR__ . '/../conn/conexao.php';
require_once __DIR__ . '/../services/RedisJobWorkerService.php';

$limite = isset($argv[1]) ? max(1, (int) $argv[1]) : 10;
$timeout = isset($argv[2]) ? max(1, (int) $argv[2]) : 5;
$worker = new RedisJobWorkerService($conexao);
$processados = 0;

try {
    for ($i = 0; $i < $limite; $i++) {
        $resultado = $worker->processarProximo($timeout);

        if (($resultado['processed'] ?? false) !== true) {
            echo '[' . date('c') . '] Redis sem jobs elegiveis no intervalo.' . PHP_EOL;
            break;
        }

        $processados++;
        $idJob = (int) ($resultado['id_job'] ?? 0);
        $resultadoItem = is_array($resultado['result'] ?? null) ? $resultado['result'] : [];
        $status = ($resultadoItem['pending'] ?? false) ? 'pendente' : (($resultadoItem['ok'] ?? false) ? 'ok' : 'erro');
        $mensagem = (string) ($resultadoItem['message'] ?? $resultado['message'] ?? '');

        echo '[' . date('c') . "] job={$idJob} status={$status} {$mensagem}" . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date('c') . '] erro_worker_redis=' . $exception->getMessage() . PHP_EOL);
    $conexao->close();
    exit(1);
}

$conexao->close();

echo '[' . date('c') . "] processados={$processados}" . PHP_EOL;

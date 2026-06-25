<?php

require_once __DIR__ . '/../config/env.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

$extensoesObrigatorias = ['mysqli'];
$extensoesFaltando = array_values(array_filter(
    $extensoesObrigatorias,
    static fn(string $extensao): bool => !extension_loaded($extensao)
));

if ($extensoesFaltando !== []) {
    fwrite(
        STDERR,
        'Extensoes obrigatorias nao carregadas no PHP CLI: ' .
        implode(', ', $extensoesFaltando) .
        '. Execute este publisher com o mesmo PHP configurado do servidor ou habilite as extensoes no php.ini.' .
        PHP_EOL
    );
    exit(1);
}

require_once __DIR__ . '/../conn/conexao.php';
require_once __DIR__ . '/../services/RedisJobPublisherService.php';

$limite = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 100;
$publisher = new RedisJobPublisherService($conexao);

try {
    $resultado = $publisher->publicarPendentes($limite);

    foreach ($resultado['jobs'] as $job) {
        $linha = '[' . date('c') . '] job=' . (int) $job['id_job'] .
            ' fila=' . (string) $job['fila_redis'] .
            ' status=' . (string) $job['status'];

        if (!empty($job['erro'])) {
            $linha .= ' erro=' . (string) $job['erro'];
        }

        echo $linha . PHP_EOL;
    }

    echo '[' . date('c') . '] reservados=' . (int) $resultado['reservados'] .
        ' publicados=' . (int) $resultado['publicados'] .
        ' falhas=' . (int) $resultado['falhas'] .
        PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date('c') . '] erro_publisher=' . $exception->getMessage() . PHP_EOL);
    $conexao->close();
    exit(1);
}

$conexao->close();

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

$envFile = $argv[1] ?? '';
$loadedValues = [];

if ($envFile !== '') {
    if (!is_file($envFile)) {
        fwrite(STDERR, "Arquivo env nao encontrado: {$envFile}\n");
        exit(1);
    }

    $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);

    if (!is_array($values)) {
        fwrite(STDERR, "Nao foi possivel ler o arquivo env: {$envFile}\n");
        exit(1);
    }

    $loadedValues = $values;
    $_ENV = array_merge($_ENV, $values);
}

$required = [
    'DB_HOST',
    'DB_USER',
    'DB_PASSWORD',
    'DB_DATABASE',
    'CONSULTA_DB_PREFIX',
    'BIDMAP_USUARIOS_API_TOKEN',
    'HUBDEV_TOKEN',
    'HUBDEV_CONTRACT',
    'CONSULTA_PROCESSOS_API_KEY',
    'PROCESSO_RAPIDO_API_KEY',
    'PROCESSO_RAPIDO_MID',
    'PROCESSO_RAPIDO_MSK',
];

$recommended = [
    'DB_PORT',
    'DB_CONNECT_TIMEOUT',
    'BIDMAP_TOOLS_LOGIN_URL',
    'BIDMAP_CHECKOUT_CREDITOS_25',
    'BIDMAP_CHECKOUT_CREDITOS_50',
    'BIDMAP_CHECKOUT_CREDITOS_100',
    'REDIS_HOST',
    'REDIS_PORT',
    'REDIS_QUEUE_PREFIX',
    'REDIS_WORKER_QUEUES',
    'PROCESSO_RAPIDO_STORAGE_DIR',
    'PROCESSO_RAPIDO_PDF_RETENTION_DAYS',
    'BIDMAP_PDF_DOWNLOAD_BASE_URL',
    'BIDMAP_PDF_DOWNLOAD_SECRET',
    'BIDMAP_PDF_DOWNLOAD_TTL_SECONDS',
];

$hasError = false;

function consulta_env_value(string $key, array $loadedValues): string
{
    if ($loadedValues !== []) {
        return trim((string) ($loadedValues[$key] ?? ''));
    }

    return trim((string) bidmap_env($key, ''));
}

function consulta_env_is_placeholder(string $value): bool
{
    $normalized = strtolower(trim($value));

    if ($normalized === '') {
        return true;
    }

    $placeholders = [
        'usuario_do_banco',
        'senha_do_banco',
        'coloque_o_token_aqui',
        'coloque_o_contract_aqui',
        'coloque_a_chave_aqui',
        'coloque_o_mid_aqui',
        'coloque_o_msk_aqui',
        'valor_aqui',
    ];

    return in_array($normalized, $placeholders, true) || str_starts_with($normalized, 'coloque_');
}

echo "Validando variaveis da ferramenta de consultas";
echo $envFile !== '' ? " em {$envFile}" : '';
echo "...\n\n";

foreach ($required as $key) {
    $value = consulta_env_value($key, $loadedValues);

    if (consulta_env_is_placeholder($value)) {
        echo "[ERRO] {$key} ausente ou com placeholder.\n";
        $hasError = true;
        continue;
    }

    echo "[OK]   {$key}\n";
}

foreach ($recommended as $key) {
    $value = consulta_env_value($key, $loadedValues);

    if (consulta_env_is_placeholder($value)) {
        echo "[AVISO] {$key} nao configurada; sera usado fallback do codigo quando existir.\n";
        continue;
    }

    echo "[OK]   {$key}\n";
}

if ($hasError) {
    echo "\nResultado: env incompleto. Corrija os itens [ERRO] antes de producao.\n";
    exit(1);
}

echo "\nResultado: variaveis obrigatorias preenchidas.\n";

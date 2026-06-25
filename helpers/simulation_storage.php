<?php

function bidmap_load_env(): void
{
    $envPath = __DIR__ . '/../.env';

    if (file_exists($envPath)) {
        $_ENV = array_merge($_ENV, parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: []);
    }
}

function simulation_storage_driver(): string
{
    bidmap_load_env();

    $driver = $_ENV['CALCULADORA_STORAGE'] ?? getenv('CALCULADORA_STORAGE') ?: 'database';
    return strtolower(trim((string) $driver));
}

function simulation_storage_is_local(): bool
{
    return simulation_storage_driver() === 'local';
}

function simulation_cache_dir(): string
{
    $configuredPath = $_ENV['CALCULADORA_CACHE_DIR'] ?? getenv('CALCULADORA_CACHE_DIR') ?: '';

    if ($configuredPath !== '') {
        return rtrim((string) $configuredPath, "\\/");
    }

    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
}

function simulation_cache_file(string $code): string
{
    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $code);

    return simulation_cache_dir() . DIRECTORY_SEPARATOR . $safeCode . '.json';
}

function simulation_cache_ensure_dir(): void
{
    $dir = simulation_cache_dir();

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function simulation_cache_save(string $code, string $encryptedData, string $email, string $title): bool
{
    simulation_cache_ensure_dir();

    $file = simulation_cache_file($code);

    if (file_exists($file)) {
        return false;
    }

    $payload = [
        'code' => $code,
        'encrypted_data' => $encryptedData,
        'email' => $email,
        'titulo' => $title,
        'created_at' => date(DATE_ATOM),
    ];

    return file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function simulation_cache_list(string $email = ''): array
{
    $dir = simulation_cache_dir();

    if (!is_dir($dir)) {
        return [];
    }

    $items = [];

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        $payload = json_decode(file_get_contents($file), true);

        if (!is_array($payload)) {
            continue;
        }

        if ($email !== '' && ($payload['email'] ?? '') !== $email) {
            continue;
        }

        $items[] = [
            'titulo' => $payload['titulo'] ?? '',
            'code' => $payload['code'] ?? pathinfo($file, PATHINFO_FILENAME),
        ];
    }

    return $items;
}

function simulation_cache_find(string $code): ?array
{
    $file = simulation_cache_file($code);

    if (!is_file($file)) {
        return null;
    }

    $payload = json_decode(file_get_contents($file), true);

    return is_array($payload) ? $payload : null;
}

function simulation_cache_delete(string $code): bool
{
    $file = simulation_cache_file($code);

    return is_file($file) ? unlink($file) : false;
}

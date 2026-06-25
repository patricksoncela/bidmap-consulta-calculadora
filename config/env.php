<?php

function bidmap_load_env(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $envPaths = array_unique([
        dirname(__DIR__) . '/.env',
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__) . '/.env.example',
    ]);

    foreach ($envPaths as $envPath) {
        if (!file_exists($envPath)) {
            continue;
        }

        $values = parse_ini_file($envPath, false, INI_SCANNER_RAW);

        if (is_array($values)) {
            $_ENV = array_merge($_ENV, $values);
            break;
        }
    }

    $loaded = true;
}

function bidmap_env(string $key, ?string $default = null): ?string
{
    bidmap_load_env();

    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

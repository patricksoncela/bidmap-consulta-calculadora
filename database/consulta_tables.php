<?php

require_once __DIR__ . '/../config/env.php';

function consulta_db_prefix(): string
{
    $prefix = trim((string) bidmap_env('CONSULTA_DB_PREFIX', ''));

    if ($prefix === '') {
        return '';
    }

    return preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
}

function consulta_table(string $baseName): string
{
    $safeName = preg_replace('/[^A-Za-z0-9_]/', '', $baseName);

    return '`' . consulta_db_prefix() . $safeName . '`';
}

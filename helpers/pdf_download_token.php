<?php

require_once __DIR__ . '/../config/env.php';

function bidmap_pdf_download_base_url(): string
{
    return trim((string) bidmap_env('BIDMAP_PDF_DOWNLOAD_BASE_URL', ''));
}

function bidmap_pdf_download_secret(): string
{
    return trim((string) bidmap_env('BIDMAP_PDF_DOWNLOAD_SECRET', ''));
}

function bidmap_pdf_download_enabled(): bool
{
    return bidmap_pdf_download_base_url() !== '' && bidmap_pdf_download_secret() !== '';
}

function bidmap_pdf_download_signature(int $consultaId, int $expiresAt): string
{
    $secret = bidmap_pdf_download_secret();

    if ($secret === '') {
        return '';
    }

    return hash_hmac('sha256', 'pdf|' . $consultaId . '|' . $expiresAt, $secret);
}

function bidmap_pdf_download_url(int $consultaId, int $ttlSeconds = 300): string
{
    $baseUrl = bidmap_pdf_download_base_url();

    if ($consultaId <= 0 || $baseUrl === '' || bidmap_pdf_download_secret() === '') {
        return '';
    }

    $expiresAt = time() + max(30, $ttlSeconds);
    $query = http_build_query([
        'consulta_id' => $consultaId,
        'exp' => $expiresAt,
        'sig' => bidmap_pdf_download_signature($consultaId, $expiresAt),
    ]);

    return $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . $query;
}

function bidmap_pdf_download_token_is_valid(int $consultaId, int $expiresAt, string $signature): bool
{
    if ($consultaId <= 0 || $expiresAt < time() || $signature === '') {
        return false;
    }

    $expected = bidmap_pdf_download_signature($consultaId, $expiresAt);

    return $expected !== '' && hash_equals($expected, $signature);
}

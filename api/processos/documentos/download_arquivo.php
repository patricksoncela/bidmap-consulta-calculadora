<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../database/consulta_tables.php';
require_once __DIR__ . '/../../../helpers/pdf_download_token.php';

function pdf_file_response(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function pdf_file_db(): mysqli
{
    $mysqli = mysqli_init();

    if (!$mysqli instanceof mysqli) {
        throw new RuntimeException('Nao foi possivel inicializar a conexao com o banco.');
    }

    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5')));
    $mysqli->real_connect(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev'),
        (int) bidmap_env('DB_PORT', '3306')
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException('Erro na conexao com o banco local: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function pdf_file_storage_dirs(): array
{
    $path = trim((string) bidmap_env('PROCESSO_RAPIDO_STORAGE_DIR', 'storage/processos_pdf'));

    if ($path === '') {
        $path = 'storage/processos_pdf';
    }

    if (preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $path)) {
        return [$path];
    }

    $appRoot = dirname(__DIR__, 3);
    $relativePath = ltrim($path, '/\\');

    return array_values(array_unique([
        $appRoot . DIRECTORY_SEPARATOR . $relativePath,
        dirname($appRoot) . DIRECTORY_SEPARATOR . $relativePath,
    ]));
}

function pdf_file_resolve_local_path(string $storedPath): string
{
    $storedPath = trim($storedPath);

    if ($storedPath === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $storedPath);
    $markers = [
        '/var/www/html/storage/processos_pdf/',
        '/storage/processos_pdf/',
        'storage/processos_pdf/',
    ];

    foreach ($markers as $marker) {
        $position = strpos($normalized, $marker);

        if ($position === false) {
            continue;
        }

        $relative = basename(substr($normalized, $position + strlen($marker)));

        foreach (pdf_file_storage_dirs() as $storageDir) {
            $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $relative;

            if (is_file($candidate) && filesize($candidate) > 0) {
                return $candidate;
            }
        }
    }

    $basename = basename($normalized);

    if ($basename !== '' && preg_match('/\.pdf$/i', $basename)) {
        foreach (pdf_file_storage_dirs() as $storageDir) {
            $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $basename;

            if (is_file($candidate) && filesize($candidate) > 0) {
                return $candidate;
            }
        }
    }

    return '';
}

function pdf_file_formatar_cnj(string $digits): string
{
    $digits = preg_replace('/\D+/', '', $digits);

    if (strlen($digits) !== 20) {
        return $digits;
    }

    return substr($digits, 0, 7) . '-' . substr($digits, 7, 2) . '.' . substr($digits, 9, 4) . '.' .
        substr($digits, 13, 1) . '.' . substr($digits, 14, 2) . '.' . substr($digits, 16, 4);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        pdf_file_response(405, 'Use GET para baixar o arquivo.');
    }

    $consultaId = (int) ($_GET['consulta_id'] ?? 0);
    $expiresAt = (int) ($_GET['exp'] ?? 0);
    $signature = (string) ($_GET['sig'] ?? '');

    if (!bidmap_pdf_download_token_is_valid($consultaId, $expiresAt, $signature)) {
        pdf_file_response(403, 'Link de download expirado ou invalido.');
    }

    $mysqli = pdf_file_db();
    $consultasExternas = consulta_table('consultas_externas');
    $sql = "SELECT id_consulta, modalidade_pedido, entrada_normalizada, status_resultado, link_pdf_cliente
            FROM {$consultasExternas}
            WHERE id_consulta = ?
              AND modalidade_pedido = 'pdf_processo'
              AND status_resultado = 'sucesso'
            LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $consultaId);
    $stmt->execute();
    $consulta = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $mysqli->close();

    if (!$consulta) {
        pdf_file_response(404, 'PDF nao encontrado.');
    }

    $filePath = pdf_file_resolve_local_path((string) ($consulta['link_pdf_cliente'] ?? ''));

    if ($filePath === '') {
        pdf_file_response(404, 'Arquivo PDF nao encontrado no servidor.');
    }

    $filename = pdf_file_formatar_cnj((string) ($consulta['entrada_normalizada'] ?? '')) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    @touch($filePath);
    readfile($filePath);
    exit;
} catch (Throwable $exception) {
    pdf_file_response(500, $exception->getMessage());
}

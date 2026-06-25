<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../database/consulta_tables.php';
require_once __DIR__ . '/../../../helpers/processos_actions.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/pdf_download_token.php';

bidmap_require_login_for_creditos();

header('Content-Type: application/json; charset=utf-8');

function pdf_status_db(): mysqli
{
    $mysqli = mysqli_init();
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

function pdf_status_response(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pdf_status_resolver_caminho(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (is_file($path)) {
        return $path;
    }

    $appRoot = dirname(__DIR__, 3);
    $relativeStorage = 'storage' . DIRECTORY_SEPARATOR . 'processos_pdf';
    $storageDirs = array_values(array_unique([
        $appRoot . DIRECTORY_SEPARATOR . $relativeStorage,
        dirname($appRoot) . DIRECTORY_SEPARATOR . $relativeStorage,
    ]));
    $normalized = str_replace('\\', '/', $path);
    $markers = [
        '/var/www/html/storage/processos_pdf/',
        '/storage/processos_pdf/',
        'storage/processos_pdf/',
    ];

    foreach ($markers as $marker) {
        $pos = strpos($normalized, $marker);

        if ($pos === false) {
            continue;
        }

        foreach ($storageDirs as $storageDir) {
            $candidate = $storageDir . DIRECTORY_SEPARATOR . basename(substr($normalized, $pos + strlen($marker)));

            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    if (preg_match('/\.pdf$/i', $path)) {
        foreach ($storageDirs as $storageDir) {
            $candidate = $storageDir . DIRECTORY_SEPARATOR . basename($path);

            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return $path;
}

function pdf_status_row_payload(array $row): array
{
    $pedidoStatus = strtolower((string) ($row['pedido_status'] ?? 'pendente'));
    $consultaStatus = strtolower((string) ($row['consulta_status'] ?? ''));
    $status = $consultaStatus !== '' ? $consultaStatus : $pedidoStatus;
    $consultaId = (int) ($row['id_consulta'] ?: $row['pedido_consulta_id'] ?: 0);
    $filePath = pdf_status_resolver_caminho($row['link_pdf_cliente'] ?? '');
    $ready = $status === 'sucesso' && $filePath !== '' && is_file($filePath) && filesize($filePath) > 0;
    $remoteDownload = false;

    if (!$ready && $status === 'sucesso' && bidmap_pdf_download_enabled() && $consultaId > 0 && trim((string) ($row['link_pdf_cliente'] ?? '')) !== '') {
        $ready = true;
        $remoteDownload = true;
    }

    if ($status === 'sucesso' && !$ready) {
        $status = 'erro';
    }

    $pending = !$ready && $status === 'pendente';

    return [
        'ok' => $ready || $pending,
        'ready' => $ready,
        'pending' => $pending,
        'pedido_id' => (int) ($row['id_consulta_usuario'] ?? 0),
        'consulta_id' => $consultaId,
        'status' => $ready ? 'sucesso' : ($pending ? 'pendente' : $status),
        'status_label' => $ready ? 'Disponível' : ($pending ? 'Pendente' : 'Erro'),
        'url' => $remoteDownload ? bidmap_pdf_download_url($consultaId, (int) bidmap_env('BIDMAP_PDF_DOWNLOAD_TTL_SECONDS', '300')) : '',
        'message' => (string) ($row['consulta_mensagem'] ?: $row['pedido_mensagem'] ?: ''),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        pdf_status_response(['ok' => false, 'message' => 'Use POST para verificar o PDF.']);
    }

    if (!processos_validate_action_token($_POST['processos_action_token'] ?? null)) {
        http_response_code(403);
        pdf_status_response(['ok' => false, 'message' => 'Acao expirada. Recarregue a pagina e tente novamente.']);
    }

    $consultaId = (int) ($_POST['consulta_id'] ?? 0);
    $pedidoId = (int) ($_POST['pedido_id'] ?? 0);
    $pedidoIdsRaw = $_POST['pedido_ids'] ?? [];
    if (is_string($pedidoIdsRaw)) {
        $decodedIds = json_decode($pedidoIdsRaw, true);
        $pedidoIdsRaw = is_array($decodedIds) ? $decodedIds : explode(',', $pedidoIdsRaw);
    }
    $pedidoIds = array_values(array_unique(array_filter(array_map('intval', (array) $pedidoIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $pedidoIds = array_slice($pedidoIds, 0, 50);
    $usuarioId = bidmap_usuario_id_sessao();

    if ($pedidoId <= 0 && $consultaId <= 0 && $pedidoIds === []) {
        pdf_status_response([
            'ok' => false,
            'ready' => false,
            'pending' => false,
            'status' => 'erro',
            'status_label' => 'Erro',
            'message' => 'Pedido de PDF nao informado.',
        ]);
    }

    $mysqli = pdf_status_db();
    $pedidosUsuarios = consulta_table('pedidos_usuarios');
    $consultasExternas = consulta_table('consultas_externas');
    $row = null;

    if ($pedidoIds !== []) {
        $placeholders = implode(',', array_fill(0, count($pedidoIds), '?'));
        $sql = "SELECT
                    p.id_consulta_usuario,
                    p.id_consulta AS pedido_consulta_id,
                    p.status_resultado AS pedido_status,
                    p.mensagem_resultado AS pedido_mensagem,
                    c.id_consulta,
                    c.status_resultado AS consulta_status,
                    c.mensagem_resultado AS consulta_mensagem,
                    c.link_pdf_cliente
                FROM {$pedidosUsuarios} p
                LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
                WHERE p.id_consulta_usuario IN ({$placeholders})
                  AND p.id_usuario = ?
                  AND p.modalidade_pedido = 'pdf_processo'";
        $stmt = $mysqli->prepare($sql);
        $types = str_repeat('i', count($pedidoIds)) . 'i';
        $params = array_merge($pedidoIds, [$usuarioId]);
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        $stmt->bind_param($types, ...$refs);
        $stmt->execute();
        $result = $stmt->get_result();
        $pedidos = [];
        while ($row = $result->fetch_assoc()) {
            $payload = pdf_status_row_payload($row);
            $pedidos[(string) $payload['pedido_id']] = $payload;
        }
        $stmt->close();
        $mysqli->close();

        pdf_status_response([
            'ok' => true,
            'pedidos' => array_values($pedidos),
        ]);
    }

    if ($pedidoId > 0) {
        $sql = "SELECT
                    p.id_consulta_usuario,
                    p.id_consulta AS pedido_consulta_id,
                    p.status_resultado AS pedido_status,
                    p.mensagem_resultado AS pedido_mensagem,
                    c.id_consulta,
                    c.status_resultado AS consulta_status,
                    c.mensagem_resultado AS consulta_mensagem,
                    c.link_pdf_cliente
                FROM {$pedidosUsuarios} p
                LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
                WHERE p.id_consulta_usuario = ?
                  AND p.id_usuario = ?
                  AND p.modalidade_pedido = 'pdf_processo'
                LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ii', $pedidoId, $usuarioId);
    } else {
        $sql = "SELECT
                    p.id_consulta_usuario,
                    p.id_consulta AS pedido_consulta_id,
                    p.status_resultado AS pedido_status,
                    p.mensagem_resultado AS pedido_mensagem,
                    c.id_consulta,
                    c.status_resultado AS consulta_status,
                    c.mensagem_resultado AS consulta_mensagem,
                    c.link_pdf_cliente
                FROM {$consultasExternas} c
                INNER JOIN {$pedidosUsuarios} p ON p.id_consulta = c.id_consulta
                WHERE c.id_consulta = ?
                  AND c.modalidade_pedido = 'pdf_processo'
                  AND p.id_usuario = ?
                  AND p.modalidade_pedido = 'pdf_processo'
                ORDER BY p.id_consulta_usuario DESC
                LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ii', $consultaId, $usuarioId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $mysqli->close();

    if (!$row) {
        pdf_status_response([
            'ok' => false,
            'ready' => false,
            'pending' => false,
            'status' => 'erro',
            'status_label' => 'Erro',
            'message' => 'Pedido de PDF nao encontrado.',
        ]);
    }

    pdf_status_response(pdf_status_row_payload($row));
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'ready' => false,
        'pending' => false,
        'status' => 'erro',
        'status_label' => 'Erro',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

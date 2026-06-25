<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../database/consulta_tables.php';
require_once __DIR__ . '/../../helpers/processos_actions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/pdf_download_token.php';

bidmap_require_login_for_creditos();

header('Content-Type: application/json; charset=utf-8');

function status_pedidos_db(): mysqli
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
        throw new RuntimeException('Erro na conexão com o banco local: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function status_pedidos_response(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    status_pedidos_response([
        'ok' => true,
        'demo' => true,
        'message' => 'Modo demo: nenhum pedido real foi consultado.',
        'pedidos' => [],
        'items' => [],
    ]);
}

function status_pedidos_request_data(): array
{
    $data = $_POST;
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

    if (strpos($contentType, 'application/json') === false) {
        return $data;
    }

    $raw = file_get_contents('php://input');
    $decoded = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : $data;
}

function status_pedidos_label(?string $status): string
{
    $labels = [
        'sucesso' => 'Disponível',
        'pendente' => 'Pendente',
        'erro' => 'Erro',
        'estornado' => 'Estornado',
        'saldo_insuficiente' => 'Saldo insuficiente',
        'expirado' => 'Expirada',
        'expirada' => 'Expirada',
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function status_pedidos_resolver_pdf(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (is_file($path)) {
        return $path;
    }

    $appRoot = dirname(__DIR__, 2);
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

function status_pedidos_url(array $row): string
{
    $modalidade = (string) ($row['modalidade_pedido'] ?? '');
    $entrada = (string) (($row['entrada_original'] ?? '') ?: ($row['entrada_normalizada'] ?? ''));
    $digitos = preg_replace('/\D+/', '', $entrada);
    $consultaId = (int) ($row['id_consulta'] ?: $row['pedido_consulta_id'] ?: 0);

    if ($modalidade === 'dados_cpf') {
        return 'resultado_dados_pessoais.php?' . http_build_query([
            'tipo_consulta' => 'cpf',
            'q' => $entrada,
            'acao' => 'dados_pessoais',
            'consulta_id' => $consultaId,
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'dados_cnpj') {
        return 'resultado_dados_pessoais.php?' . http_build_query([
            'tipo_consulta' => 'cnpj',
            'q' => $entrada,
            'acao' => 'dados_pessoais',
            'consulta_id' => $consultaId,
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'consulta_processos' && strlen((string) $digitos) === 11) {
        return 'resultado_processos_documento.php?' . http_build_query([
            'tipo_consulta' => 'cpf',
            'q' => $entrada,
            'acao' => 'buscar_processos',
            'consulta_id' => $consultaId,
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'consulta_processos' && strlen((string) $digitos) === 14) {
        return 'resultado_processos_documento.php?' . http_build_query([
            'tipo_consulta' => 'cnpj',
            'q' => $entrada,
            'acao' => 'buscar_processos',
            'consulta_id' => $consultaId,
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'pdf_processo' && strlen((string) $digitos) === 20) {
        return 'api/processos/documentos/pdf.php?' . http_build_query([
            'consulta_id' => $consultaId,
            'numero' => $entrada,
            'origem' => 'historico',
            'return' => 'consultar_processos.php#historico-consultas',
            'processos_action_token' => processos_action_token(),
        ]);
    }

    return 'detalhe_processo.php?' . http_build_query([
        'q' => $entrada,
        'consulta_id' => $consultaId,
        'origem' => 'historico',
    ]);
}

function status_pedidos_payload(array $row): array
{
    $modalidade = (string) ($row['modalidade_pedido'] ?? '');
    $pedidoStatus = strtolower((string) ($row['pedido_status'] ?? 'pendente'));
    $consultaStatus = strtolower((string) ($row['consulta_status'] ?? ''));
    $status = $consultaStatus !== '' ? $consultaStatus : $pedidoStatus;
    $validade = trim((string) ($row['data_validade'] ?? ''));
    $consultaId = (int) ($row['id_consulta'] ?: $row['pedido_consulta_id'] ?: 0);
    $remoteDownload = false;

    if ($status === 'sucesso' && $validade !== '') {
        $timestamp = strtotime($validade);

        if ($timestamp !== false && $timestamp < time()) {
            $status = 'expirada';
        }
    }

    $ready = $status === 'sucesso';

    if ($ready && $modalidade === 'pdf_processo') {
        $filePath = status_pedidos_resolver_pdf($row['link_pdf_cliente'] ?? '');
        $ready = $filePath !== '' && is_file($filePath) && filesize($filePath) > 0;

        if (!$ready && bidmap_pdf_download_enabled() && $consultaId > 0 && trim((string) ($row['link_pdf_cliente'] ?? '')) !== '') {
            $ready = true;
            $remoteDownload = true;
        }

        if (!$ready) {
            $status = 'erro';
        }
    }

    $pending = !$ready && $status === 'pendente';

    $message = (string) ($row['consulta_mensagem'] ?: $row['pedido_mensagem'] ?: '');

    if ($modalidade === 'pdf_processo' && $pending) {
        $message = 'Consultando dados e criando PDF completo. A página será atualizada automaticamente quando o PDF estiver disponível.';
    }

    return [
        'ok' => true,
        'pedido_id' => (int) ($row['id_consulta_usuario'] ?? 0),
        'consulta_id' => $consultaId,
        'modalidade' => $modalidade,
        'ready' => $ready,
        'pending' => $pending,
        'status' => $ready ? 'sucesso' : ($pending ? 'pendente' : $status),
        'status_label' => status_pedidos_label($ready ? 'sucesso' : ($pending ? 'pendente' : $status)),
        'button_text' => $modalidade === 'pdf_processo' ? 'Baixar' : 'Abrir',
        'url' => $ready
            ? ($remoteDownload ? bidmap_pdf_download_url($consultaId, (int) bidmap_env('BIDMAP_PDF_DOWNLOAD_TTL_SECONDS', '300')) : status_pedidos_url($row))
            : '',
        'message' => $message,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        status_pedidos_response(['ok' => false, 'message' => 'Use POST para verificar pedidos.']);
    }

    $requestData = status_pedidos_request_data();

    if (!processos_validate_action_token($requestData['processos_action_token'] ?? null)) {
        http_response_code(403);
        status_pedidos_response(['ok' => false, 'message' => 'Ação expirada. Recarregue a página e tente novamente.']);
    }

    $pedidoIdsRaw = $requestData['ids'] ?? $requestData['pedido_ids'] ?? [];
    if (is_string($pedidoIdsRaw)) {
        $decodedIds = json_decode($pedidoIdsRaw, true);
        $pedidoIdsRaw = is_array($decodedIds) ? $decodedIds : explode(',', $pedidoIdsRaw);
    }

    $pedidoIds = array_values(array_unique(array_filter(array_map('intval', (array) $pedidoIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $pedidoIds = array_slice($pedidoIds, 0, 50);

    if ($pedidoIds === []) {
        status_pedidos_response(['ok' => false, 'message' => 'Nenhum pedido informado.', 'pedidos' => []]);
    }

    $usuarioId = bidmap_usuario_id_sessao();
    $mysqli = status_pedidos_db();
    $pedidosUsuarios = consulta_table('pedidos_usuarios');
    $consultasExternas = consulta_table('consultas_externas');
    $placeholders = implode(',', array_fill(0, count($pedidoIds), '?'));

    $sql = "SELECT
                p.id_consulta_usuario,
                p.modalidade_pedido,
                p.entrada_original,
                p.entrada_normalizada,
                p.id_consulta AS pedido_consulta_id,
                p.status_resultado AS pedido_status,
                p.mensagem_resultado AS pedido_mensagem,
                c.id_consulta,
                c.status_resultado AS consulta_status,
                c.mensagem_resultado AS consulta_mensagem,
                c.data_validade,
                c.link_pdf_cliente,
                c.link_original_pdf
            FROM {$pedidosUsuarios} p
            LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
            WHERE p.id_consulta_usuario IN ({$placeholders})
              AND p.id_usuario = ?";

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
        $payload = status_pedidos_payload($row);
        $pedidos[(string) $payload['pedido_id']] = $payload;
    }

    $stmt->close();
    $mysqli->close();

    status_pedidos_response([
        'ok' => true,
        'pedidos' => array_values($pedidos),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'pedidos' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

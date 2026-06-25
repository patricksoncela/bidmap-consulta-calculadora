<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../services/UsuarioCreditoService.php';

date_default_timezone_set((string) bidmap_env('APP_TIMEZONE', 'America/Sao_Paulo'));

function usage(): void
{
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/corrigir_debito_interno_duplicado.php listar [dias]\n");
    fwrite(STDERR, "  php scripts/corrigir_debito_interno_duplicado.php estornar <id_pedido> [motivo]\n");
}

function db(): mysqli
{
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5')));
    $ok = $mysqli->real_connect(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev'),
        (int) bidmap_env('DB_PORT', '3306')
    );

    if (!$ok) {
        throw new RuntimeException('Erro ao conectar no banco: ' . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array
{
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar SQL: ' . $mysqli->error);
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function listar(mysqli $mysqli, int $dias): array
{
    $pedidos = consulta_table('pedidos_usuarios');
    $inicio = date('Y-m-d H:i:s', strtotime('-' . max(1, $dias) . ' days'));

    return rows(
        $mysqli,
        "SELECT id_usuario, modalidade_pedido, entrada_normalizada,
                COUNT(*) AS total,
                SUM(creditos_usuario_consumidos) AS creditos,
                GROUP_CONCAT(id_consulta_usuario ORDER BY data_pedido, id_consulta_usuario) AS pedidos,
                GROUP_CONCAT(status_resultado ORDER BY data_pedido, id_consulta_usuario) AS statuses,
                GROUP_CONCAT(COALESCE(id_consulta, 0) ORDER BY data_pedido, id_consulta_usuario) AS consultas,
                MIN(data_pedido) AS primeiro,
                MAX(data_pedido) AS ultimo
         FROM {$pedidos}
         WHERE data_pedido >= ?
           AND creditos_usuario_consumidos > 0
           AND status_resultado IN ('pendente', 'sucesso')
         GROUP BY id_usuario, modalidade_pedido, entrada_normalizada
         HAVING COUNT(*) > 1
         ORDER BY ultimo DESC
         LIMIT 100",
        's',
        [$inicio]
    );
}

function estornarPedido(mysqli $mysqli, int $pedidoId, string $motivo): array
{
    $pedidoModel = new PedidoUsuarioModel($mysqli);
    $pedido = $pedidoModel->buscarPorId($pedidoId);

    if (!$pedido) {
        throw new RuntimeException('Pedido nao encontrado: ' . $pedidoId);
    }

    if (!empty($pedido['credito_estorno_transacao_id']) || ($pedido['status_resultado'] ?? '') === 'estornado') {
        return [
            'ok' => true,
            'ja_estornado' => true,
            'pedido_id' => $pedidoId,
            'mensagem' => 'Pedido ja estava estornado.',
        ];
    }

    $creditos = (float) ($pedido['creditos_usuario_consumidos'] ?? 0);

    if ($creditos <= 0) {
        throw new RuntimeException('Pedido nao possui creditos internos para estornar: ' . $pedidoId);
    }

    $entrada = (string) ($pedido['entrada_original'] ?: $pedido['entrada_normalizada']);
    $modalidade = (string) ($pedido['modalidade_pedido'] ?? 'consulta_processos');
    $descricao = 'Estorno por debito duplicado - ' . $modalidade . ' - entrada ' . $entrada;

    if ($motivo !== '') {
        $descricao .= ' - ' . $motivo;
    }

    $creditoService = new UsuarioCreditoService();
    $estorno = $creditoService->estornar((int) $pedido['id_usuario'], $creditos, $descricao);
    $pedidoModel->registrarTransacaoCredito($pedidoId, $estorno, true);
    $pedidoModel->marcarErro($pedidoId, 'Estornado por debito duplicado.', 'estornado');

    return [
        'ok' => true,
        'pedido_id' => $pedidoId,
        'id_usuario' => (int) $pedido['id_usuario'],
        'creditos_estornados' => $creditos,
        'saldo_anterior' => $estorno['saldo_anterior'] ?? null,
        'saldo_posterior' => $estorno['saldo_posterior'] ?? null,
        'transacao_id' => $estorno['transacao_id'] ?? null,
    ];
}

$acao = (string) ($argv[1] ?? '');

try {
    if (!in_array($acao, ['listar', 'estornar'], true)) {
        usage();
        exit(1);
    }

    $mysqli = db();

    if ($acao === 'listar') {
        $dias = max(1, (int) ($argv[2] ?? 3));
        $resultado = [
            'ok' => true,
            'acao' => 'listar',
            'dias' => $dias,
            'duplicidades' => listar($mysqli, $dias),
        ];
    } else {
        $pedidoId = (int) ($argv[2] ?? 0);

        if ($pedidoId <= 0) {
            usage();
            exit(1);
        }

        $resultado = estornarPedido($mysqli, $pedidoId, trim((string) ($argv[3] ?? '')));
    }

    $mysqli->close();
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'erro' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../services/ProcessoService.php';
require_once __DIR__ . '/../../../helpers/processos_actions.php';
require_once __DIR__ . '/../../../helpers/auth.php';

bidmap_require_login_for_creditos();

function fila_adicionar_db(): mysqli
{
    $mysqli = new mysqli(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev')
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException('Erro na conexao com o banco local: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function fila_adicionar_redirect(array $params): void
{
    header('Location: ../../../resultado_processos_documento.php?' . http_build_query($params));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Use POST para adicionar a consulta à fila.';
        exit;
    }

    if (!processos_validate_action_token($_POST['processos_action_token'] ?? null)) {
        fila_adicionar_redirect([
            'consulta_erro' => 'Acao expirada. Recarregue a pagina e tente novamente.',
            'return' => 'consultar_processos.php',
        ]);
    }

    $acao = (string) ($_POST['acao'] ?? '');
    $documento = trim((string) ($_POST['q'] ?? ''));
    $tipoConsulta = strtolower((string) ($_POST['tipo_consulta'] ?? ''));
    $atualizar = (string) ($_POST['atualizar'] ?? '') === '1';

    if ($acao !== 'buscar_processos') {
        fila_adicionar_redirect([
            'consulta_erro' => 'Acao invalida para a fila de processos.',
            'return' => 'consultar_processos.php',
        ]);
    }

    $mysqli = fila_adicionar_db();
    $service = new ProcessoService($mysqli);
    $resultado = $service->buscarPorDocumento($documento, $tipoConsulta, 1, 100, null, null, $atualizar);
    $mysqli->close();

    if (($resultado['ok'] ?? false) !== true) {
        fila_adicionar_redirect([
            'consulta_erro' => (string) ($resultado['message'] ?? 'Nao foi possivel adicionar a consulta a fila.'),
            'return' => 'consultar_processos.php',
        ]);
    }

    $params = [
        'tipo_consulta' => $tipoConsulta,
        'q' => $documento,
        'acao' => 'buscar_processos',
    ];

    if (!empty($resultado['consulta_id'])) {
        $params['consulta_id'] = (int) $resultado['consulta_id'];
    }

    if (empty($resultado['pendente']) && !empty($resultado['consulta_id'])) {
        $params['origem'] = 'historico';
    }

    fila_adicionar_redirect($params);
} catch (Throwable $exception) {
    fila_adicionar_redirect([
        'consulta_erro' => $exception->getMessage(),
        'return' => 'consultar_processos.php',
    ]);
}

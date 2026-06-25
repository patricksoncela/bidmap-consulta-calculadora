<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/services/ProcessoService.php';
require_once __DIR__ . '/database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/database/models/CustoConsultaModel.php';
require_once __DIR__ . '/helpers/processos_actions.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/credit_modal.php';
require_once __DIR__ . '/helpers/account_menu.php';
require_once __DIR__ . '/helpers/demo_data.php';

bidmap_require_login_for_creditos();

if (false && function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    $consulta = bidmap_demo_find_consulta((int) ($_GET['consulta_id'] ?? 0));
    $entrada = (string) ($_GET['q'] ?? ($consulta['entrada_original'] ?? '123.456.789-00'));

    bidmap_demo_render_page(
        'Busca de processos - Demo',
        'Lista mockada de processos encontrados para a entrada consultada.',
        [
            'Entrada consultada' => $entrada,
            'Processo encontrado 1' => '1000000-00.2024.8.26.0100 - Procedimento Comum Civel',
            'Processo encontrado 2' => '1000001-00.2024.8.26.0100 - Execucao de Titulo Extrajudicial',
            'Fornecedor' => 'Consulta de Processos',
            'Status' => 'Disponivel',
        ]
    );
}

function processos_view_env_bool(string $key, bool $default = false): bool
{
    $value = bidmap_env($key);

    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function processos_view_db(): mysqli
{
    $mysqli = new mysqli(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev')
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException('Erro na conexão com o banco local: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function processos_view_creditos(): UsuarioCreditoService
{
    static $service = null;

    if ($service instanceof UsuarioCreditoService) {
        return $service;
    }

    if (!processos_view_env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false)) {
        $service = new UsuarioCreditoService();
        return $service;
    }

    $service = new class extends UsuarioCreditoService {
        public function getUsuarioId(): int
        {
            return (int) bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '1');
        }

        public function getSaldo(int $idUsuario): float
        {
            return (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '0');
        }

        public function debitar(int $idUsuario, float $creditos, string $referencia): array
        {
            $saldo = $this->getSaldo($idUsuario);

            return [
                'saldo_anterior' => $saldo,
                'saldo_posterior' => $saldo,
                'bypass_creditos' => true,
            ];
        }

        public function estornar(int $idUsuario, float $creditos, string $referencia): array
        {
            $saldo = $this->getSaldo($idUsuario);

            return [
                'saldo_anterior' => $saldo,
                'saldo_posterior' => $saldo,
                'bypass_creditos' => true,
            ];
        }
    };

    return $service;
}

function processos_view_creditos_disponiveis(): float
{
    try {
        $service = processos_view_creditos();
        return $service->getSaldo($service->getUsuarioId());
    } catch (Throwable $exception) {
        return 0.0;
    }
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function display_text(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtoupper') && function_exists('mb_convert_case')) {
        $upper = mb_strtoupper($value, 'UTF-8');

        if ($value === $upper && mb_strlen($value, 'UTF-8') > 8) {
            return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
    }

    return $value;
}

function filtro_label(?string $value): string
{
    $labels = [
        'CIVEL' => 'Cível',
        'CRIMINAL' => 'Criminal',
        'TRABALHISTA' => 'Trabalhista',
        'FAZENDA' => 'Fazenda',
        'PREVIDENCIARIA' => 'Previdenciária',
        'ADMINISTRATIVA' => 'Administrativa',
        'ACTIVE' => 'Autor',
        'PASSIVE' => 'Réu',
        'NEUTRAL' => 'Outros',
    ];

    return $labels[$value ?? ''] ?? display_text($value);
}

function filtro_key(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? $value;
    return strtoupper(trim($value, '_'));
}

function format_documento(?string $value, string $tipo): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);

    if ($tipo === 'cpf' && strlen($digits) === 11) {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits);
    }

    if ($tipo === 'cnpj' && strlen($digits) === 14) {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
    }

    return (string) $value;
}

function format_cnj(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);

    if (strlen($digits) !== 20) {
        return (string) $value;
    }

    return preg_replace('/^(\d{7})(\d{2})(\d{4})(\d{1})(\d{2})(\d{4})$/', '$1-$2.$3.$4.$5.$6', $digits);
}

function first_value(array $data, array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && $data[$key] !== '') {
            return is_scalar($data[$key]) ? (string) $data[$key] : null;
        }
    }

    return $default;
}

function processos_extract_list(array $raw): array
{
    $candidates = [
        $raw['lawsuits'] ?? null,
        $raw['processos'] ?? null,
        $raw['items'] ?? null,
        $raw['data']['lawsuits'] ?? null,
        $raw['data']['processos'] ?? null,
        $raw['data']['items'] ?? null,
        $raw['result']['lawsuits'] ?? null,
        $raw['result']['processos'] ?? null,
        $raw['result']['items'] ?? null,
        $raw['results'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            return array_values(array_filter($candidate, 'is_array'));
        }
    }

    if (function_exists('array_is_list') && array_is_list($raw)) {
        return array_values(array_filter($raw, 'is_array'));
    }

    return [];
}

function processos_count_by(array $processos, array $keys): array
{
    $counts = [];

    foreach ($processos as $processo) {
        $value = first_value($processo, $keys, 'Não informado') ?: 'Não informado';
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    arsort($counts);
    return $counts;
}

function incluir_filtro_ativo(array $counts, ?string $selecionado): array
{
    if ($selecionado === null) {
        return $counts;
    }

    foreach (array_keys($counts) as $value) {
        if (filtro_key((string) $value) === $selecionado) {
            return $counts;
        }
    }

    return [$selecionado => 0] + $counts;
}

function filtro_url(string $grupo, string $valor): string
{
    $params = $_GET;
    $valorNormalizado = filtro_key($valor);
    unset($params['proc_page']);

    if (filtro_key($params[$grupo] ?? '') === $valorNormalizado) {
        unset($params[$grupo]);
    } else {
        $params[$grupo] = $valorNormalizado;
    }

    return 'resultado_processos_documento.php?' . http_build_query($params);
}

function render_filter_link(string $grupo, string $valor, int $count, ?string $selecionado): void
{
    $ativo = $selecionado === filtro_key($valor);
    $classes = 'filter-option' . ($ativo ? ' active' : '');

    echo '<a class="' . h($classes) . '" href="' . h(filtro_url($grupo, $valor)) . '">';
    echo '<span>' . h(filtro_label($valor)) . '</span>';
    echo '<strong>' . h((string) $count) . '</strong>';
    echo '</a>';
}

function filtrar_processos(array $processos, ?string $courtType, ?string $polarity, ?string $state): array
{
    return array_values(array_filter($processos, function (array $processo) use ($courtType, $polarity, $state): bool {
        if ($courtType && filtro_key(first_value($processo, ['courtType', 'type', 'classe', 'tipo'], '')) !== $courtType) {
            return false;
        }

        if ($polarity && filtro_key(first_value($processo, ['documentPolarity', 'tipo_parte', 'papel', 'polo'], '')) !== $polarity) {
            return false;
        }

        if ($state && filtro_key(first_value($processo, ['state', 'estado', 'uf'], '')) !== $state) {
            return false;
        }

        return true;
    }));
}

function processos_resultado_salvo(mysqli $mysqli, int $consultaId): ?array
{
    if ($consultaId <= 0) {
        return null;
    }

    $consulta = (new ConsultaExternaModel($mysqli))->buscarPorId($consultaId);

    if (
        !$consulta
        || ($consulta['modalidade_pedido'] ?? '') !== 'consulta_processos'
        || ($consulta['status_resultado'] ?? '') !== 'sucesso'
    ) {
        return null;
    }

    $dados = json_decode((string) ($consulta['dados_json'] ?? ''), true);

    if (!is_array($dados)) {
        return null;
    }

    return [
        'ok' => true,
        'origem' => 'historico',
        'consulta_id' => $consultaId,
        'dados' => $dados,
    ];
}

function processos_resultado_por_pedido(mysqli $mysqli, int $pedidoId): ?array
{
    if ($pedidoId <= 0) {
        return null;
    }

    $pedido = (new PedidoUsuarioModel($mysqli))->buscarPorId($pedidoId);

    if (!$pedido) {
        return [
            'ok' => false,
            'message' => 'Pedido nao encontrado.',
        ];
    }

    $usuarioAtual = processos_view_creditos()->getUsuarioId();
    if ((int) ($pedido['id_usuario'] ?? 0) !== $usuarioAtual) {
        return [
            'ok' => false,
            'message' => 'Pedido nao pertence ao usuario atual.',
        ];
    }

    $status = strtolower((string) ($pedido['status_resultado'] ?? ''));
    $consultaId = (int) ($pedido['id_consulta'] ?? 0);

    if ($status === 'sucesso' && $consultaId > 0) {
        $resultado = processos_resultado_salvo($mysqli, $consultaId);

        if ($resultado) {
            $resultado['pedido_id'] = $pedidoId;
            return $resultado;
        }

        return [
            'ok' => false,
            'message' => 'Resultado concluido, mas os processos salvos nao foram encontrados.',
        ];
    }

    if ($status === 'pendente') {
        return [
            'ok' => true,
            'pendente' => true,
            'origem' => 'fila',
            'pedido_id' => $pedidoId,
            'consulta_id' => $consultaId ?: null,
            'request_id' => null,
            'dados' => [],
            'message' => (string) ($pedido['mensagem_resultado'] ?? 'Consulta aguardando processamento.'),
            'saldo_anterior' => isset($pedido['saldo_anterior_usuario']) ? (float) $pedido['saldo_anterior_usuario'] : null,
            'saldo_posterior' => isset($pedido['saldo_posterior_usuario']) ? (float) $pedido['saldo_posterior_usuario'] : null,
        ];
    }

    return [
        'ok' => false,
        'message' => (string) ($pedido['mensagem_resultado'] ?? 'Nao foi possivel concluir esta consulta.'),
    ];
}

function processos_refresh_url_pendente(array $resultado, string $documento, string $tipoConsulta): string
{
    $params = [
        'tipo_consulta' => $tipoConsulta,
        'q' => $documento,
        'acao' => 'buscar_processos',
    ];

    $pedidoId = (int) ($resultado['pedido_id'] ?? 0);
    if ($pedidoId > 0) {
        $params['pedido_id'] = $pedidoId;
    }

    return 'resultado_processos_documento.php?' . http_build_query(array_filter(
        $params,
        static fn($value) => $value !== null && $value !== ''
    ));
}

function processos_url_resultado_neutra(array $resultado, string $documento, string $tipoConsulta): ?string
{
    $params = [
        'tipo_consulta' => $tipoConsulta,
        'q' => $documento,
        'acao' => 'buscar_processos',
    ];

    $pedidoId = (int) ($resultado['pedido_id'] ?? 0);
    $consultaId = (int) ($resultado['consulta_id'] ?? 0);

    if (!empty($resultado['pendente']) && $pedidoId > 0) {
        $params['pedido_id'] = $pedidoId;
    } elseif ($consultaId > 0) {
        $params['consulta_id'] = $consultaId;
        $params['origem'] = 'historico';
    } elseif ($pedidoId > 0) {
        $params['pedido_id'] = $pedidoId;
    } else {
        return null;
    }

    return 'resultado_processos_documento.php?' . http_build_query($params);
}

$creditos = processos_view_creditos_disponiveis();
$documento = $_GET['q'] ?? '';
$tipoConsulta = strtolower($_GET['tipo_consulta'] ?? '');
$consultaIdHistorico = (int) ($_GET['consulta_id'] ?? 0);
$pedidoIdAcompanhamento = (int) ($_GET['pedido_id'] ?? 0);
$abrindoHistorico = ($_GET['origem'] ?? '') === 'historico';
$courtTypeFiltro = filtro_key($_GET['courtType'] ?? '');
$polarityFiltro = filtro_key($_GET['polarity'] ?? '');
$stateFiltro = filtro_key($_GET['state'] ?? '');
$courtTypeFiltro = $courtTypeFiltro !== '' ? $courtTypeFiltro : null;
$polarityFiltro = $polarityFiltro !== '' ? $polarityFiltro : null;
$stateFiltro = $stateFiltro !== '' ? $stateFiltro : null;
$paginaProcessos = max(1, (int) ($_GET['proc_page'] ?? 1));
$processosPorPagina = 20;
$resultado = null;
$erro = null;
$creditosConsultaCompleta = 0.0;
$creditosPdfProcesso = 0.0;
$isPortfolioDemo = function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode();

function processos_format_creditos(float $creditos): string
{
    return number_format($creditos, 2, '.', '');
}

if ($isPortfolioDemo) {
    $consultaDemo = bidmap_demo_find_consulta($consultaIdHistorico);
    $documento = (string) ($documento ?: ($consultaDemo['entrada_original'] ?? '123.456.789-00'));
    $tipoConsulta = $tipoConsulta ?: 'cpf';
    $creditosConsultaCompleta = 3.0;
    $creditosPdfProcesso = 12.0;
    $resultado = [
        'ok' => true,
        'origem' => 'portfolio_demo',
        'consulta_id' => $consultaIdHistorico ?: 9003,
        'dados' => bidmap_demo_processos_documento((string) $documento),
    ];
} else {
try {
    $mysqli = processos_view_db();
    $tipoPedidoModel = new TipoPedidoModel($mysqli);
    $custoConsultaModel = new CustoConsultaModel($mysqli);
    $tipoConsultaProcessos = $tipoPedidoModel->buscarPorModalidade('detalhes_processo');
    $tipoPdfProcesso = $tipoPedidoModel->buscarPorModalidade('pdf_processo');
    $custoDetalhesProcesso = $custoConsultaModel->buscarPorChave('detalhes_processo');
    $custoPdfProcesso = $custoConsultaModel->buscarPorChave('pdf_processo');

    if ($custoDetalhesProcesso) {
        $creditosConsultaCompleta = (float) ($custoDetalhesProcesso['creditos_usuario'] ?? 0);
    } elseif ($tipoConsultaProcessos) {
        $creditosConsultaCompleta = (float) ($tipoConsultaProcessos['creditos_usuario'] ?? 0);
    }

    if ($custoPdfProcesso) {
        $creditosPdfProcesso = (float) ($custoPdfProcesso['creditos_usuario'] ?? 0);
    } elseif ($tipoPdfProcesso) {
        $creditosPdfProcesso = (float) ($tipoPdfProcesso['creditos_usuario'] ?? 0);
    }

    if ($pedidoIdAcompanhamento > 0) {
        $resultado = processos_resultado_por_pedido($mysqli, $pedidoIdAcompanhamento);
    } elseif ($abrindoHistorico) {
        $resultado = processos_resultado_salvo($mysqli, $consultaIdHistorico);
    }

    if ($abrindoHistorico && !$resultado) {
        $resultado = [
            'ok' => false,
            'message' => 'Não foi possível abrir esta consulta salva.',
        ];
    } elseif (!$resultado) {
        $service = new ProcessoService($mysqli, null, processos_view_creditos());
        $resultado = $service->buscarPorDocumento($documento, $tipoConsulta, 1, 100, null, null, ($_GET['atualizar'] ?? '') === '1');

        if (!empty($resultado['ok'])) {
            $redirectNeutro = processos_url_resultado_neutra($resultado, (string) $documento, (string) $tipoConsulta);

            if ($redirectNeutro !== null) {
                $mysqli->close();
                header('Location: ' . $redirectNeutro);
                exit;
            }
        }
    }

    $mysqli->close();

    if (!$resultado['ok']) {
        $erro = $resultado['message'] ?? 'Não foi possível consultar os processos.';
    }
} catch (Throwable $exception) {
    $erro = $exception->getMessage();
}
}

if ($erro && (string) ($_GET['return'] ?? '') === 'consultar_processos.php') {
    header('Location: consultar_processos.php?' . http_build_query([
        'consulta_erro' => $erro,
    ]));
    exit;
}

$raw = is_array($resultado['dados'] ?? null) ? $resultado['dados'] : [];
$processosSemFiltro = $erro ? [] : processos_extract_list($raw);
$processos = filtrar_processos($processosSemFiltro, $courtTypeFiltro, $polarityFiltro, $stateFiltro);
$totalProcessos = count($processos);
$totalProcessosSemFiltro = count($processosSemFiltro);
$totalPaginasProcessos = max(1, (int) ceil($totalProcessos / $processosPorPagina));
$paginaProcessos = min($paginaProcessos, $totalPaginasProcessos);
$processosPagina = array_slice($processos, ($paginaProcessos - 1) * $processosPorPagina, $processosPorPagina);
$processoInicioPagina = $totalProcessos > 0 ? (($paginaProcessos - 1) * $processosPorPagina) + 1 : 0;
$processoFimPagina = $totalProcessos > 0 ? min($totalProcessos, $paginaProcessos * $processosPorPagina) : 0;
$tipoLabel = strtoupper($tipoConsulta ?: 'DOC');
$nomePrincipal = first_value($raw, ['nome', 'nomeCompleto', 'razao_social', 'razaoSocial'], 'Documento pesquisado');
$tipoProcessoCounts = processos_count_by($processosSemFiltro, ['courtType', 'type', 'classe', 'tipo']);
$parteCounts = processos_count_by($processosSemFiltro, ['documentPolarity', 'tipo_parte', 'papel', 'polo']);
$estadoCounts = processos_count_by($processosSemFiltro, ['state', 'estado', 'uf']);
$tipoProcessoCounts = incluir_filtro_ativo($tipoProcessoCounts, $courtTypeFiltro);
$parteCounts = incluir_filtro_ativo($parteCounts, $polarityFiltro);
$estadoCounts = incluir_filtro_ativo($estadoCounts, $stateFiltro);
$consultaPendente = !empty($resultado['pendente']);
$refreshUrlPendente = $consultaPendente ? processos_refresh_url_pendente($resultado, (string) $documento, (string) $tipoConsulta) : '';
$documentoFormatado = format_documento($documento, $tipoConsulta);
$consultaId = (int) ($resultado['consulta_id'] ?? 0);
$creditosConsultaCompletaLabel = processos_format_creditos($creditosConsultaCompleta);
$creditosPdfProcessoLabel = processos_format_creditos($creditosPdfProcesso);
$actionToken = processos_action_token();
$resultadoReturnUrl = 'resultado_processos_documento.php?' . http_build_query($_GET);

if (!defined('BIDMAP_HEADER_ASSETS_LOADED')) {
    define('BIDMAP_HEADER_ASSETS_LOADED', true);
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
    <link rel="stylesheet" href="css/header.css?v=<?php echo filemtime('css/header.css'); ?>">
    <link rel="stylesheet" href="css/consultar_processos.css?v=<?php echo filemtime('css/consultar_processos.css'); ?>">
    <script src="js/header.js?v=<?php echo filemtime('js/header.js'); ?>"></script>
    <script src="https://kit.fontawesome.com/1ad52ad40a.js" crossorigin="anonymous"></script>
    <title>Processos Encontrados</title>
    <?php if ($consultaPendente): ?>
        <meta http-equiv="refresh" content="5;url=<?= h($refreshUrlPendente); ?>">
    <?php endif; ?>
</head>

<body>
    <div id="main">
        <?php include_once 'header/header.php'; ?>

        <main class="resultado-page">
            <section class="resultado-toolbar" aria-label="Navegação da consulta">
                <div class="resultado-breadcrumb">
                    <a href="consultar_processos.php">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        Consulta de processos
                    </a>
                    <span></span>
                    <strong>Processos (<?= h((string) $totalProcessosSemFiltro); ?>)</strong>
                </div>

                <div class="credit-actions">
                    <?php if (bidmap_usuario_pode_acessar_dashboard()): ?>
                        <a class="dashboard-shortcut" href="painel_creditos.php">
                            <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                            Dashboard
                        </a>
                    <?php endif; ?>
                    <a class="dashboard-shortcut tribunais-shortcut" href="#" aria-disabled="true">
                        <i class="fa-solid fa-landmark" aria-hidden="true"></i>
                        Tribunais
                    </a>
                    <button class="credit-pill" type="button" aria-label="Créditos disponíveis" data-credit-modal-open>
                    <i class="fa-solid fa-coins" aria-hidden="true"></i>
                    <strong><?= h(processos_format_creditos($creditos)); ?></strong>
                    Créditos
                    <span class="credit-add">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </span>
                    </button>
                </div>
            </section>

            <section class="resultado-layout">
                <aside class="filters-panel" aria-label="Filtros de processos">
                    <div class="filter-group">
                        <h2>Tipo do processo</h2>
                        <?php foreach (array_slice($tipoProcessoCounts, 0, 6, true) as $label => $count): ?>
                            <?php render_filter_link('courtType', (string) $label, (int) $count, $courtTypeFiltro); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-group">
                        <h2>Tipo de parte</h2>
                        <?php foreach (array_slice($parteCounts, 0, 4, true) as $label => $count): ?>
                            <?php render_filter_link('polarity', (string) $label, (int) $count, $polarityFiltro); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-group">
                        <h2>Estado</h2>
                        <?php foreach (array_slice($estadoCounts, 0, 6, true) as $label => $count): ?>
                            <?php render_filter_link('state', (string) $label, (int) $count, $stateFiltro); ?>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <section class="resultado-content">
                    <div class="person-summary">
                        <div>
                            <h1><?= h(display_text($nomePrincipal)); ?></h1>
                            <p><span><?= h($tipoLabel); ?></span><?= h($documentoFormatado); ?></p>
                        </div>
                    </div>

                    <?php if ($erro): ?>
                        <div class="dados-alert">
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                            <div>
                                <strong>Não foi possível concluir a consulta</strong>
                                <p><?= h($erro); ?></p>
                            </div>
                        </div>
                    <?php elseif ($consultaPendente): ?>
                        <div class="dados-alert loading-alert" role="status" aria-live="polite">
                            <span class="loading-spinner" aria-hidden="true"></span>
                            <div>
                                <strong>Consulta em processamento</strong>
                                <p>A consulta foi enviada e esta página será atualizada automaticamente.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="result-heading">
                            <h2><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Processos encontrados</h2>
                            <p>
                                Localizamos <strong><?= h((string) $totalProcessos); ?> processos</strong> onde este documento foi citado
                                <?php if ($totalProcessos !== $totalProcessosSemFiltro): ?>
                                    <span class="filtered-count">de <?= h((string) $totalProcessosSemFiltro); ?> no total</span>
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php if (empty($processos)): ?>
                            <div class="empty-state">
                                <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                                <span>
                                    <?= ($courtTypeFiltro || $polarityFiltro || $stateFiltro)
                                        ? 'Nenhum processo corresponde aos filtros selecionados.'
                                        : 'Nenhum processo retornado para este documento.'; ?>
                                </span>
                                <?php if ($courtTypeFiltro || $polarityFiltro || $stateFiltro): ?>
                                    <a href="resultado_processos_documento.php?<?= h(http_build_query(array_diff_key($_GET, array_flip(['courtType', 'polarity', 'state'])))); ?>">Limpar filtros</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="process-list">
                                <?php foreach ($processosPagina as $processo): ?>
                                    <?php
                                    $numero = first_value($processo, ['number', 'numero', 'numero_processo', 'numeroProcesso', 'cnj'], '');
                                    $autor = first_value($processo, ['author', 'autor'], '');
                                    $reu = first_value($processo, ['reu', 'defendant', 'réu'], '');
                                    $titulo = trim($autor . ($autor !== '' && $reu !== '' ? ' x ' : '') . $reu);
                                    $titulo = $titulo !== '' ? $titulo : first_value($processo, ['titulo', 'nome', 'resumo'], 'Processo encontrado');
                                    $status = first_value($processo, ['courtType', 'type', 'status', 'situacao'], 'Indefinido');
                                    $instancia = first_value($processo, ['instance', 'instancia', 'grau'], 'Instância não informada');
                                    $classe = first_value($processo, ['mainSubject', 'classe', 'tipo'], 'Classe não informada');
                                    $assunto = first_value($processo, ['iBroadCNJSubjectName', 'iCNJSubjectName', 'mainSubject', 'assunto'], '');
                                    $localParts = array_filter([
                                        first_value($processo, ['lawsuitHostService'], ''),
                                        first_value($processo, ['courtName'], ''),
                                        first_value($processo, ['courtDistrict'], ''),
                                        first_value($processo, ['state'], ''),
                                    ]);
                                    $local = implode(' | ', $localParts);
                                    $instanciaRaw = first_value($processo, ['instance', 'instancia', 'grau'], '');
                                    $tagStatus = filtro_label($status);
                                    $tagInstancia = $instanciaRaw !== '' ? $instanciaRaw . 'ª instância' : 'Instância não informada';
                                    $tagClasse = first_value($processo, ['iBroadCNJSubjectName', 'courtType', 'type'], $classe);
                                    $assunto = first_value($processo, ['mainSubject', 'iCNJSubjectName', 'assunto'], $assunto);
                                    $numeroFormatado = format_cnj($numero);
                                    $previewUrl = 'detalhe_processo.php?' . http_build_query([
                                        'q' => $numero,
                                        'consulta_id' => $consultaId,
                                        'return' => $resultadoReturnUrl,
                                    ]);
                                    $pdfReturnUrl = 'resultado_processos_documento.php?' . http_build_query($_GET);
                                    $completeFormId = 'complete-process-' . md5($numero . $consultaId);
                                    $pdfFormId = 'download-process-' . md5($numero . $consultaId);
                                    ?>
                                    <article class="process-card process-card-clickable"
                                        data-preview-url="<?= h($previewUrl); ?>"
                                        role="link"
                                        tabindex="0"
                                        aria-label="Abrir prévia do processo <?= h($numeroFormatado); ?>">
                                        <div class="process-card-content">
                                            <div class="tags-row">
                                                <span><?= h($tagStatus); ?></span>
                                                <span><?= h($tagInstancia); ?></span>
                                                <span><?= h(display_text($tagClasse)); ?></span>
                                            </div>
                                            <h3><?= h(display_text($titulo)); ?></h3>
                                            <p class="process-number"><?= h($numeroFormatado); ?></p>
                                            <?php if ($assunto !== ''): ?>
                                                <p class="process-subject"><?= h(display_text($assunto)); ?></p>
                                            <?php endif; ?>
                                            <?php if ($local !== ''): ?>
                                                <p class="process-location">
                                                    <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                                                    <?= h($local); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="process-card-actions" aria-label="Ações do processo <?= h($numeroFormatado); ?>">
                                            <button class="process-action complete js-paid-confirm"
                                                type="button"
                                                data-form-id="<?= h($completeFormId); ?>"
                                                data-title="Ver detalhes do processo: <?= h($numeroFormatado); ?>"
                                                data-message="Ser&atilde;o gastos"
                                                data-credit-label="<?= h($creditosConsultaCompletaLabel); ?>">
                                                Ver detalhes do processo
                                            </button>
                                            <button class="process-action download js-paid-confirm"
                                                type="button"
                                                data-form-id="<?= h($pdfFormId); ?>"
                                                data-title="Baixar processo na &iacute;ntegra: <?= h($numeroFormatado); ?>"
                                                data-message="Ser&atilde;o gastos"
                                                data-credit-label="<?= h($creditosPdfProcessoLabel); ?>">
                                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                                                Baixar na íntegra
                                            </button>
                                            <form id="<?= h($completeFormId); ?>" method="post" action="detalhe_processo.php" hidden>
                                                <input type="hidden" name="q" value="<?= h($numero); ?>">
                                                <input type="hidden" name="acao" value="consultar_processo">
                                                <input type="hidden" name="return" value="<?= h($resultadoReturnUrl); ?>">
                                                <input type="hidden" name="processos_action_token" value="<?= h($actionToken); ?>">
                                            </form>
                                            <form id="<?= h($pdfFormId); ?>" method="post" action="api/processos/documentos/pdf.php" hidden>
                                                <input type="hidden" name="numero" value="<?= h($numero); ?>">
                                                <input type="hidden" name="return" value="<?= h($pdfReturnUrl); ?>">
                                                <input type="hidden" name="processos_action_token" value="<?= h($actionToken); ?>">
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($totalPaginasProcessos > 1): ?>
                                <nav class="process-pagination" aria-label="Páginas de processos">
                                    <span class="process-pagination-summary">
                                        Mostrando <?= h((string) $processoInicioPagina); ?>-<?= h((string) $processoFimPagina); ?>
                                        de <?= h((string) $totalProcessos); ?> processos
                                    </span>
                                    <?php
                                    $pageParams = $_GET;
                                    unset($pageParams['proc_page']);
                                    ?>
                                    <a class="<?= $paginaProcessos <= 1 ? 'disabled' : ''; ?>" href="resultado_processos_documento.php?<?= h(http_build_query($pageParams + ['proc_page' => max(1, $paginaProcessos - 1)])); ?>">Anterior</a>
                                    <?php for ($i = max(1, $paginaProcessos - 2); $i <= min($totalPaginasProcessos, $paginaProcessos + 2); $i++): ?>
                                        <a class="page-number <?= $i === $paginaProcessos ? 'current' : ''; ?>" href="resultado_processos_documento.php?<?= h(http_build_query($pageParams + ['proc_page' => $i])); ?>"><?= h((string) $i); ?></a>
                                    <?php endfor; ?>
                                    <a class="<?= $paginaProcessos >= $totalPaginasProcessos ? 'disabled' : ''; ?>" href="resultado_processos_documento.php?<?= h(http_build_query($pageParams + ['proc_page' => min($totalPaginasProcessos, $paginaProcessos + 1)])); ?>">Próxima</a>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </section>
        </main>
        <?php bidmap_render_credit_modal((float) $creditos); ?>
    </div>
    <div class="paid-confirm-modal" id="paidConfirmModal" hidden>
        <div class="paid-confirm-backdrop" data-paid-cancel></div>
        <section class="paid-confirm-card" role="dialog" aria-modal="true" aria-labelledby="paidConfirmTitle">
            <button class="paid-confirm-close" type="button" data-paid-cancel aria-label="Fechar">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <div class="paid-confirm-icon">
                <i class="fa-solid fa-coins" aria-hidden="true"></i>
            </div>
            <h2 id="paidConfirmTitle">Confirmar consulta</h2>
            <p id="paidConfirmMessage">Esta ação pode consumir créditos.</p>
            <div class="paid-confirm-actions">
                <button type="button" data-paid-cancel>Cancelar</button>
                <a href="#" id="paidConfirmSubmit">Confirmar</a>
            </div>
        </section>
    </div>
    <div class="processos-loading-overlay" id="processosLoadingOverlay" hidden>
        <div class="processos-loading-card" role="status" aria-live="polite">
            <button class="processos-loading-close" type="button" aria-label="Fechar aviso de andamento" data-loading-close>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <span class="loading-spinner" aria-hidden="true"></span>
            <div>
                <strong id="processosLoadingTitle">Consultando dados</strong>
                <p id="processosLoadingMessage">Aguarde enquanto buscamos as informações. A tela será atualizada quando o retorno estiver completo.</p>
            </div>
            <div class="processos-loading-actions">
                <button class="processos-loading-ok" type="button" data-loading-close>OK</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const modal = document.getElementById('paidConfirmModal');
            const title = document.getElementById('paidConfirmTitle');
            const message = document.getElementById('paidConfirmMessage');
            const submit = document.getElementById('paidConfirmSubmit');
            const loadingOverlay = document.getElementById('processosLoadingOverlay');
            const loadingTitle = document.getElementById('processosLoadingTitle');
            const loadingMessage = document.getElementById('processosLoadingMessage');
            const creditosDisponiveis = <?= json_encode((float) $creditos); ?>;
            let pendingForm = null;

            function showProcessosLoading(titleText, messageText) {
                if (!loadingOverlay) {
                    return;
                }

                if (loadingTitle && titleText) {
                    loadingTitle.textContent = titleText;
                }

                if (loadingMessage) {
                    loadingMessage.textContent = messageText || '';
                    loadingMessage.hidden = !messageText;
                }

                loadingOverlay.hidden = false;
                document.body.classList.add('processos-is-loading');
            }

            function hideProcessosLoading() {
                if (!loadingOverlay) {
                    return;
                }

                loadingOverlay.hidden = true;
                document.body.classList.remove('processos-is-loading');
            }

            if (!modal || !title || !message || !submit) {
                return;
            }

            function closeModal() {
                modal.hidden = true;
                submit.setAttribute('href', '#');
                submit.hidden = false;
                pendingForm = null;
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatCredits(value) {
                const numeric = Number(value || 0);
                return numeric.toFixed(2);
            }

            function parseCredits(value) {
                return Number(String(value || '').replace(',', '.')) || 0;
            }

            function insufficientCreditsMessage(cost) {
                return 'Esta a&ccedil;&atilde;o exige ' +
                    escapeHtml(formatCredits(cost)) +
                    ' cr&eacute;ditos. Seu saldo atual &eacute; de ' +
                    escapeHtml(formatCredits(creditosDisponiveis)) +
                    ' cr&eacute;ditos.<a href="#" data-insufficient-add-credits>Adicionar cr&eacute;ditos</a>';
            }

            function openCreditModalFromPaidConfirm() {
                closeModal();

                if (typeof window.bidmapOpenCreditModal === 'function') {
                    window.bidmapOpenCreditModal();
                }
            }

            document.querySelectorAll('.js-paid-confirm').forEach(function (trigger) {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    title.textContent = 'Confirmar consulta';
                    const actionText = trigger.getAttribute('data-title') || 'Confirmar consulta';
                    message.textContent = trigger.getAttribute('data-message') || 'Esta ação pode consumir créditos.';
                    const creditLabel = trigger.getAttribute('data-credit-label');
                    const cost = parseCredits(creditLabel);

                    if (cost > 0 && creditosDisponiveis < cost) {
                        title.textContent = 'Créditos insuficientes';
                        message.innerHTML = insufficientCreditsMessage(cost);
                        submit.hidden = true;
                        pendingForm = null;
                        modal.hidden = false;
                        return;
                    }

                    submit.hidden = false;
                    if (creditLabel) {
                        message.innerHTML = escapeHtml(actionText) + '. Serão gastos <strong>' + escapeHtml(creditLabel + ' créditos') + '</strong>.';
                    }
                    pendingForm = trigger.getAttribute('data-form-id')
                        ? document.getElementById(trigger.getAttribute('data-form-id'))
                        : null;
                    submit.setAttribute('href', trigger.getAttribute('href') || '#');
                    modal.hidden = false;
                });
            });

            submit.addEventListener('click', function (event) {
                if (!pendingForm) {
                    return;
                }

                event.preventDefault();
                const formAction = pendingForm.getAttribute('action') || '';
                modal.hidden = true;

                if (formAction.indexOf('documentos/pdf') !== -1) {
                    title.textContent = 'Seu processo esta em andamento';
                    message.textContent = 'Consultando dados e criando PDF completo. A página será atualizada automaticamente quando o PDF estiver disponível.';
                    submit.hidden = true;
                    modal.hidden = false;
                    window.setTimeout(function () {
                        pendingForm.submit();
                    }, 700);
                    return;
                } else {
                    showProcessosLoading('Consultando dados', '');
                }

                pendingForm.submit();
            });

            modal.addEventListener('click', function (event) {
                const link = event.target.closest('[data-insufficient-add-credits]');

                if (!link) {
                    return;
                }

                event.preventDefault();
                openCreditModalFromPaidConfirm();
            });

            document.querySelectorAll('.process-card-clickable').forEach(function (card) {
                function openPreview() {
                    const url = card.getAttribute('data-preview-url');
                    if (url) {
                        window.location.href = url;
                    }
                }

                card.addEventListener('click', function (event) {
                    if (event.target.closest('a, button')) {
                        return;
                    }

                    openPreview();
                });

                card.addEventListener('keydown', function (event) {
                    if (event.target.closest('a, button')) {
                        return;
                    }

                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openPreview();
                    }
                });
            });

            modal.querySelectorAll('[data-paid-cancel]').forEach(function (button) {
                button.addEventListener('click', closeModal);
            });

            document.querySelectorAll('[data-loading-close]').forEach(function (button) {
                button.addEventListener('click', hideProcessosLoading);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        })();
    </script>
</body>

</html>

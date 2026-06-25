<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/database/consulta_tables.php';
require_once __DIR__ . '/services/UsuarioCreditoService.php';
require_once __DIR__ . '/services/CreditoExtratoSyncService.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/credit_modal.php';
require_once __DIR__ . '/helpers/account_menu.php';
require_once __DIR__ . '/helpers/demo_data.php';

bidmap_require_login_for_creditos();

function historico_db(): mysqli
{
    $mysqli = mysqli_init();

    if (!$mysqli instanceof mysqli) {
        throw new RuntimeException('Nao foi possivel inicializar a conexao com o banco.');
    }

    $connectTimeout = max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5'));
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, $connectTimeout);
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

function historico_usuario_id(): int
{
    try {
        return historico_credito_service()->getUsuarioId();
    } catch (Throwable $exception) {
        return (int) bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '1478');
    }
}

function historico_credito_service(): UsuarioCreditoService
{
    static $service = null;

    if (!$service instanceof UsuarioCreditoService) {
        $service = new UsuarioCreditoService();
    }

    return $service;
}

function historico_creditos_disponiveis(): float
{
    try {
        $service = historico_credito_service();
        return $service->getSaldo($service->getUsuarioId());
    } catch (Throwable $exception) {
        return 0.0;
    }
}

function historico_deve_sincronizar_extrato(int $idUsuario, int $pagina): bool
{
    if ($pagina !== 1) {
        return false;
    }

    if ((string) ($_GET['sync'] ?? '') === '1') {
        return true;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    $ttl = max(0, (int) bidmap_env('BIDMAP_EXTRATO_SYNC_TTL', '300'));

    if ($ttl === 0) {
        return false;
    }

    $sessionKey = 'bidmap_extrato_synced_at_' . $idUsuario;
    $lastSync = $_SESSION[$sessionKey] ?? null;

    return !is_numeric($lastSync) || (time() - (int) $lastSync) >= $ttl;
}

function historico_marcar_extrato_sincronizado(int $idUsuario): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['bidmap_extrato_synced_at_' . $idUsuario] = time();
}

function historico_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function historico_formatar_descricao_extrato(string $descricao, string $tituloPadrao, string $dataEvento): array
{
    $descricao = trim($descricao);
    $dataFormatada = '';
    $timestamp = strtotime($dataEvento);

    if ($timestamp !== false) {
        $dataFormatada = date('d/m/Y H:i', $timestamp);
    }

    if (preg_match('/^(.*?)\s+em\s+(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2})\s+-\s+(?:entrada|documento|CNJ)\s+(.+)$/iu', $descricao, $matches)) {
        return [
            'titulo' => trim($matches[1]) . ' "' . trim($matches[3]) . '" em ' . trim($matches[2]),
            'detalhe' => '',
            'data' => $dataFormatada !== '' ? $dataFormatada : trim($matches[2]),
        ];
    }

    return [
        'titulo' => $tituloPadrao,
        'detalhe' => $descricao,
        'data' => $dataFormatada,
    ];
}

function historico_tipo_label(?string $modalidade): string
{
    $labels = [
        'dados_cpf' => 'Dados por CPF',
        'dados_cnpj' => 'Dados por CNPJ',
        'consulta_processos' => 'Busca de processos',
        'detalhes_processo' => 'Detalhes do processo',
        'pdf_processo' => 'PDF do processo',
    ];

    return $labels[$modalidade ?? ''] ?? ucfirst(str_replace('_', ' ', (string) $modalidade));
}

function historico_titulo_consulta(array $row): string
{
    if (!empty($row['is_update'])) {
        $modalidade = (string) ($row['modalidade_pedido'] ?? '');
        $entrada = $row['entrada_original'] ?: $row['entrada_normalizada'];
        $digitos = preg_replace('/\D+/', '', (string) $entrada);

        if ($modalidade === 'consulta_processos') {
            return strlen($digitos) === 14
                ? 'Atualização de processos por CNPJ'
                : 'Atualização de processos por CPF';
        }

        if ($modalidade === 'detalhes_processo') {
            return 'Atualização do processo por CNJ';
        }

        if ($modalidade === 'dados_cpf') {
            return 'Atualização dos dados por CPF';
        }

        if ($modalidade === 'dados_cnpj') {
            return 'Atualização dos dados por CNPJ';
        }

        return 'Atualização de consulta já pesquisada';
    }

    $modalidade = (string) ($row['modalidade_pedido'] ?? '');
    $entrada = $row['entrada_original'] ?: $row['entrada_normalizada'];
    $digitos = preg_replace('/\D+/', '', (string) $entrada);

    if ($modalidade === 'consulta_processos') {
        if (strlen($digitos) === 11) {
            return 'Busca de processos por CPF';
        }

        if (strlen($digitos) === 14) {
            return 'Busca de processos por CNPJ';
        }

        return 'Busca de processos';
    }

    if ($modalidade === 'dados_cpf') {
        return 'Dados pessoais por CPF';
    }

    if ($modalidade === 'dados_cnpj') {
        return 'Dados empresariais por CNPJ';
    }

    if ($modalidade === 'detalhes_processo') {
        return 'Visualização completa do processo por CNJ';
    }

    if ($modalidade === 'pdf_processo') {
        return 'Download do processo na íntegra';
    }

    return historico_tipo_label($modalidade);
}

function historico_status_label(?string $status): string
{
    $labels = [
        'sucesso' => 'Disponível',
        'pendente' => 'Pendente',
        'erro' => 'Erro',
        'estornado' => 'Estornado',
        'confirmado' => 'Confirmado',
        'confirmada' => 'Confirmado',
        'saldo_insuficiente' => 'Saldo insuficiente',
        'expirada' => 'Expirada',
        'expirado' => 'Expirada',
    ];

    return $labels[$status ?? ''] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function historico_status(array $row): string
{
    $status = strtolower((string) ($row['status_resultado'] ?? 'pendente'));
    $validade = trim((string) ($row['data_validade'] ?? ''));

    if ($status === 'sucesso' && $validade !== '') {
        $timestamp = strtotime($validade);

        if ($timestamp !== false && $timestamp < time()) {
            return 'expirada';
        }
    }

    return $status;
}

function historico_formatar_entrada(?string $valor, ?string $modalidade): string
{
    $valor = (string) $valor;

    if (trim($valor) === '') {
        return '';
    }
    $digitos = preg_replace('/\D+/', '', $valor);

    if (($modalidade === 'dados_cpf' || $modalidade === 'consulta_processos') && strlen($digitos) === 11) {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digitos);
    }

    if (($modalidade === 'dados_cnpj' || $modalidade === 'consulta_processos') && strlen($digitos) === 14) {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digitos);
    }

    if (strlen($digitos) === 20) {
        return preg_replace('/^(\d{7})(\d{2})(\d{4})(\d{1})(\d{2})(\d{4})$/', '$1-$2.$3.$4.$5.$6', $digitos);
    }

    return $valor;
}

function historico_resumo(array $row): string
{
    if (!empty($row['is_update'])) {
        return 'Nova busca feita para atualizar a entrada ' . historico_formatar_entrada(
            $row['entrada_original'] ?: $row['entrada_normalizada'],
            $row['modalidade_pedido'] ?? ''
        ) . '.';
    }

    $dados = json_decode((string) ($row['dados_json'] ?? ''), true);

    if (!is_array($dados)) {
        $mensagem = trim((string) ($row['mensagem_resultado'] ?? ''));
        return preg_match('/fornecedor externo|cache interno|resultado consultado/i', $mensagem) ? '' : $mensagem;
    }

    $candidatos = [
        $dados['nomeCompleto'] ?? null,
        $dados['nome'] ?? null,
        $dados['razao_social'] ?? null,
        $dados['razaoSocial'] ?? null,
        $dados['processo']['classe'] ?? null,
        $dados['classe'] ?? null,
        $dados['mainSubject'] ?? null,
        $dados['assunto'] ?? null,
        $dados['message'] ?? null,
    ];

    foreach ($candidatos as $valor) {
        if (is_scalar($valor) && trim((string) $valor) !== '') {
            return (string) $valor;
        }
    }

    if (isset($dados['lawsuits']) && is_array($dados['lawsuits'])) {
        return count($dados['lawsuits']) . ' processo(s) encontrados.';
    }

    if (isset($dados['processos']) && is_array($dados['processos'])) {
        return count($dados['processos']) . ' processo(s) encontrados.';
    }

    $mensagem = trim((string) ($row['mensagem_resultado'] ?? ''));
    return preg_match('/fornecedor externo|cache interno|resultado consultado/i', $mensagem) ? '' : $mensagem;
}

$pagina = max(1, (int) ($_GET['page'] ?? 1));
$limite = 10;
$offset = ($pagina - 1) * $limite;
$total = 0;
$transacoes = [];
$erro = null;
$syncAviso = null;
$creditos = historico_creditos_disponiveis();

try {
    $mysqli = historico_db();
    $idUsuario = historico_usuario_id();

    if (historico_deve_sincronizar_extrato($idUsuario, $pagina)) {
        try {
            (new CreditoExtratoSyncService($mysqli))->sincronizarUsuario($idUsuario);
            historico_marcar_extrato_sincronizado($idUsuario);
        } catch (Throwable $syncException) {
            $syncAviso = 'Nao foi possivel sincronizar com a API de creditos agora. Exibindo o extrato local.';
        }
    }

    $extratoCreditos = consulta_table('extrato_creditos_usuario');
    $countStmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM {$extratoCreditos} WHERE id_usuario = ?");
    $countStmt->bind_param('i', $idUsuario);
    $countStmt->execute();
    $total = (int) (($countStmt->get_result()->fetch_assoc() ?: [])['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT
                id_extrato AS evento_id,
                tipo,
                valor,
                descricao,
                ativo,
                status,
                data_movimentacao AS data_evento,
                idtransacao,
                origem
            FROM {$extratoCreditos}
            WHERE id_usuario = ?
            ORDER BY data_movimentacao DESC, id_extrato DESC
            LIMIT ? OFFSET ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iii', $idUsuario, $limite, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $transacoes[] = $row;
    }

    $stmt->close();

    $mysqli->close();
} catch (Throwable $exception) {
    $erro = $exception->getMessage();
}

$totalPaginas = max(1, (int) ceil($total / $limite));
$inicioPagina = $total > 0 ? $offset + 1 : 0;
$fimPagina = min($offset + count($transacoes), $total);

if (function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    $erro = null;
    $syncAviso = null;
    $creditos = (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '100');
    $demoTransacoes = bidmap_demo_extrato();
    $total = count($demoTransacoes);
    $transacoes = array_slice($demoTransacoes, $offset, $limite);
    $totalPaginas = max(1, (int) ceil($total / $limite));
    $inicioPagina = $total > 0 ? $offset + 1 : 0;
    $fimPagina = min($offset + count($transacoes), $total);
}

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
    <title>Extrato de créditos</title>
</head>

<body>
    <div id="main">
        <?php include_once 'header/header.php'; ?>

        <main class="processos-page history-page">
            <section class="processos-topbar" aria-label="Resumo de créditos">
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
                        <strong><?= historico_h(number_format($creditos, 2, '.', '')); ?></strong>
                        Créditos
                        <span class="credit-add">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        </span>
                    </button>
                </div>
            </section>

            <section class="history-page-header">
                <div>
                    <div class="history-page-title-row">
                        <h1>Extrato de créditos</h1>
                        <a class="history-page-inline-action" href="historico_consultas.php?sync=1">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            Atualizar extrato
                        </a>
                    </div>
                    <p>Acompanhe entradas, consumos, estornos e cancelamentos sincronizados com a conta.</p>
                </div>
                <div class="history-page-header-actions">
                    <a href="consultar_processos.php">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        Voltar para consulta
                    </a>
                </div>
            </section>

            <section class="history-page-list" aria-label="Extrato de créditos">
                <?php if ($syncAviso): ?>
                    <div class="empty-state sync-warning">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        <span><?= historico_h($syncAviso); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($erro): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <span><?= historico_h($erro); ?></span>
                    </div>
                <?php elseif (empty($transacoes)): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                        <span>Nenhuma movimentação de crédito registrada ainda.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($transacoes as $consulta): ?>
                        <?php
                        $status = strtolower((string) ($consulta['status'] ?? 'ativo'));
                        $statusClass = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($status));
                        $tipo = strtoupper((string) ($consulta['tipo'] ?? ''));
                        $valor = (float) ($consulta['valor'] ?? 0);
                        $descricao = trim((string) ($consulta['descricao'] ?? ''));
                        $sinal = $tipo === 'C' ? '+' : '-';
                        $titulo = $tipo === 'C' ? 'Entrada de créditos' : 'Consumo de créditos';
                        $statusLabel = [
                            'ativo' => 'Ativo',
                            'cancelado' => 'Cancelado',
                            'estorno' => 'Estorno',
                            'inativo' => 'Inativo',
                        ][$status] ?? ucfirst($status);
                        $textoExtrato = historico_formatar_descricao_extrato(
                            $descricao,
                            $titulo,
                            (string) $consulta['data_evento']
                        );
                        ?>
                        <?php
                        $eventClasses = ['history-page-item'];
                        if ($tipo === 'C') {
                            $eventClasses[] = 'is-credit';
                        }
                        ?>
                        <article class="<?= historico_h(implode(' ', $eventClasses)); ?>">
                            <div class="history-page-main">
                                <?php if ($textoExtrato['data'] !== ''): ?>
                                    <span><?= historico_h($textoExtrato['data']); ?></span>
                                <?php endif; ?>
                                <strong><?= historico_h($textoExtrato['titulo']); ?></strong>
                                <?php if ($textoExtrato['detalhe'] !== ''): ?>
                                    <p><?= historico_h($textoExtrato['detalhe']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($consulta['idtransacao'])): ?>
                                    <small>Transa&ccedil;&atilde;o <?= historico_h((string) $consulta['idtransacao']); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="history-page-side">
                                <?php if ($status !== 'ativo'): ?>
                                    <span class="status-pill status-<?= historico_h($statusClass); ?>">
                                        <?= historico_h($statusLabel); ?>
                                    </span>
                                <?php endif; ?>
                                <strong><?= historico_h($sinal . number_format($valor, 2, '.', '')); ?> créditos</strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($total > 0): ?>
                    <nav class="process-pagination" aria-label="Páginas do extrato">
                        <span class="process-pagination-summary">
                            Mostrando <?= historico_h((string) $inicioPagina); ?>-<?= historico_h((string) $fimPagina); ?>
                            de <?= historico_h((string) $total); ?> movimentações
                        </span>
                        <a class="<?= $pagina <= 1 ? 'disabled' : ''; ?>" href="historico_consultas.php?page=<?= historico_h((string) max(1, $pagina - 1)); ?>">Anterior</a>
                        <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): ?>
                            <a class="<?= $i === $pagina ? 'current' : ''; ?>" href="historico_consultas.php?page=<?= historico_h((string) $i); ?>"><?= historico_h((string) $i); ?></a>
                        <?php endfor; ?>
                        <a class="<?= $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="historico_consultas.php?page=<?= historico_h((string) min($totalPaginas, $pagina + 1)); ?>">Próxima</a>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
        <?php bidmap_render_credit_modal((float) $creditos); ?>
    </div>
</body>

</html>

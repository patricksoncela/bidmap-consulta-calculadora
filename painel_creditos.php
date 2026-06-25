<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/database/consulta_tables.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/services/UsuarioCreditoService.php';
require_once __DIR__ . '/database/models/ControleCreditoExternoModel.php';
require_once __DIR__ . '/database/models/CustoConsultaModel.php';
require_once __DIR__ . '/helpers/demo_data.php';

bidmap_require_login_for_creditos();

function painel_usuario_id(): int
{
    $id = bidmap_usuario_id_sessao();

    if ($id !== null) {
        return $id;
    }

    return (int) bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '0');
}

function painel_admin_autorizado(int $idUsuario): bool
{
    return bidmap_usuario_pode_acessar_dashboard();
}

function painel_db(): mysqli
{
    $mysqli = new mysqli(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev')
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException($mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function painel_money(float $value): string
{
    return number_format($value, 2, '.', '');
}

function painel_data_br($value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable $exception) {
        return $value;
    }
}

function painel_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function painel_url(string $view, array $params = []): string
{
    return 'painel_creditos.php?' . http_build_query(array_merge(['view' => $view], $params));
}

function painel_fornecedores_padrao(): array
{
    return ['hubdev', 'consultaprocesso', 'processorapido'];
}

function painel_custos_operacoes(): array
{
    $mysqli = painel_db();
    $custos = (new CustoConsultaModel($mysqli))->listar();
    $mysqli->close();

    return $custos;
}

$usuarioLogado = painel_usuario_id();

if (!painel_admin_autorizado($usuarioLogado)) {
    http_response_code(403);
    echo 'Acesso restrito.';
    exit;
}

$viewsPermitidas = ['home', 'conta', 'consumo', 'historico', 'externos', 'adicionar_externo', 'custos'];
$view = (string) ($_GET['view'] ?? $_POST['view'] ?? 'home');

if (!in_array($view, $viewsPermitidas, true)) {
    $view = 'home';
}

$service = new UsuarioCreditoService();
$mensagem = null;
$erro = null;
$custosOperacoes = [];
$usuarioBusca = trim((string) ($_GET['usuario'] ?? $_POST['usuario'] ?? $_GET['id_usuario'] ?? $_POST['id_usuario'] ?? $usuarioLogado));
$usuarioConsulta = ctype_digit($usuarioBusca) ? (int) $usuarioBusca : 0;

if ($usuarioConsulta <= 0 && filter_var($usuarioBusca, FILTER_VALIDATE_EMAIL)) {
    $emailSessao = (string) ($_SESSION['cliente']['email'] ?? '');
    if (strcasecmp($usuarioBusca, $emailSessao) === 0) {
        $usuarioConsulta = $usuarioLogado;
    }
}

if ((function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mensagem = 'Modo demo: a acao foi simulada, mas nenhum dado foi persistido.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');
    $valor = (float) str_replace(',', '.', (string) ($_POST['valor'] ?? '0'));
    $descricao = trim((string) ($_POST['descricao'] ?? 'Ajuste manual no painel'));

    try {
        if ($valor <= 0 && !in_array($acao, ['ajustar_externo', 'atualizar_custo'], true)) {
            throw new RuntimeException('Informe um valor maior que zero.');
        }

        if (in_array($acao, ['creditar', 'debitar'], true)) {
            if ($usuarioConsulta <= 0) {
                throw new RuntimeException('Informe um ID de usuário válido.');
            }

            if ($acao === 'creditar') {
                $service->creditar($usuarioConsulta, $valor, $descricao);
                $mensagem = 'Crédito adicionado com sucesso.';
            } else {
                $service->debitar($usuarioConsulta, $valor, $descricao);
                $mensagem = 'Débito aplicado com sucesso.';
            }
        } elseif (in_array($acao, ['creditar_externo', 'debitar_externo', 'ajustar_externo'], true)) {
            $fornecedor = trim((string) ($_POST['fornecedor'] ?? ''));

            if ($fornecedor === '') {
                throw new RuntimeException('Informe o fornecedor da plataforma externa.');
            }

            $mysqli = painel_db();
            $model = new ControleCreditoExternoModel($mysqli);

            if ($acao === 'creditar_externo') {
                $model->registrarCredito($fornecedor, $valor, $descricao);
                $mensagem = 'Créditos externos adicionados com sucesso.';
            } elseif ($acao === 'debitar_externo') {
                $model->registrarDebito($fornecedor, null, $valor, $descricao);
                $mensagem = 'Débito externo registrado com sucesso.';
            } else {
                $saldoFinal = (float) str_replace(',', '.', (string) ($_POST['saldo_final'] ?? '0'));
                $model->registrarAjuste($fornecedor, $saldoFinal, $descricao);
                $mensagem = 'Saldo externo ajustado com sucesso.';
            }

            $mysqli->close();
            $view = 'externos';
        } elseif ($acao === 'atualizar_custo') {
            $chave = trim((string) ($_POST['chave'] ?? ''));
            $creditosUsuario = (float) str_replace(',', '.', (string) ($_POST['creditos_usuario'] ?? '0'));
            $custoExterno = (float) str_replace(',', '.', (string) ($_POST['custo_externo'] ?? '0'));
            $observacao = trim((string) ($_POST['observacao'] ?? ''));

            if ($chave === '') {
                throw new RuntimeException('Registro de custo inválido.');
            }

            if ($creditosUsuario < 0 || $custoExterno < 0) {
                throw new RuntimeException('Os custos não podem ser negativos.');
            }

            $mysqli = painel_db();
            (new CustoConsultaModel($mysqli))->atualizar($chave, $creditosUsuario, $custoExterno, $observacao);
            $mysqli->close();
            $mensagem = 'Custo atualizado com sucesso.';
            $view = 'custos';
        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (Throwable $exception) {
        $erro = $exception->getMessage();
    }
}

if ($view === 'custos' && !(function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode())) {
    try {
        $custosOperacoes = painel_custos_operacoes();
    } catch (Throwable $exception) {
        $erro = $erro ?: 'Não foi possível carregar a tabela de custos.';
    }
}

$saldoUsuario = null;

if ($view === 'conta' && $usuarioConsulta > 0) {
    try {
        $saldoUsuario = $service->getSaldo($usuarioConsulta);
    } catch (Throwable $exception) {
        $erro = $erro ?: 'Não foi possível consultar o saldo: ' . $exception->getMessage();
    }
} elseif ($view === 'conta') {
    $erro = $erro ?: 'Para consultar por e-mail, o banco precisa guardar o e-mail nos pedidos. Hoje o histórico local está vinculado pelo ID do usuário.';
}

$metricas = [
    'pedidos' => 0,
    'creditos_usuario' => 0.0,
    'creditos_externos' => 0.0,
    'consultas_web' => 0,
];
$historico = [];
$historicoUsuario = [];
$fornecedores = [];
$saldosExternos = [];
$tipos = [];

try {
    $mysqli = painel_db();
    $pedidosUsuarios = consulta_table('pedidos_usuarios');
    $controleCreditoExterno = consulta_table('controle_credito_externo');

    $result = $mysqli->query(
        "SELECT
            COUNT(*) AS pedidos,
            COALESCE(SUM(creditos_usuario_consumidos), 0) AS creditos_usuario,
            COALESCE(SUM(credito_externo_consumido), 0) AS creditos_externos,
            SUM(CASE WHEN origem = 'web' THEN 1 ELSE 0 END) AS consultas_web
         FROM {$pedidosUsuarios}"
    );

    if ($result && ($row = $result->fetch_assoc())) {
        $metricas = [
            'pedidos' => (int) $row['pedidos'],
            'creditos_usuario' => (float) $row['creditos_usuario'],
            'creditos_externos' => (float) $row['creditos_externos'],
            'consultas_web' => (int) $row['consultas_web'],
        ];
    }

    if ($view === 'home' || $view === 'consumo') {
        $result = $mysqli->query(
            "SELECT
                    CASE
                        WHEN modalidade_pedido = 'processo' THEN 'consulta_processos'
                        ELSE modalidade_pedido
                    END AS modalidade_pedido,
                    COALESCE(NULLIF(fornecedor, ''), 'sem fornecedor') AS fornecedor,
                    COUNT(*) AS total,
                    COALESCE(SUM(creditos_usuario_consumidos), 0) AS creditos_usuario,
                    COALESCE(SUM(credito_externo_consumido), 0) AS creditos_externos
             FROM {$pedidosUsuarios}
             GROUP BY
                CASE
                    WHEN modalidade_pedido = 'processo' THEN 'consulta_processos'
                    ELSE modalidade_pedido
                END,
                COALESCE(NULLIF(fornecedor, ''), 'sem fornecedor')
             ORDER BY modalidade_pedido ASC, fornecedor ASC
             LIMIT 100"
        );

        while ($result && ($row = $result->fetch_assoc())) {
            $tipos[] = $row;
        }
    }

    if ($view === 'historico') {
        $stmt = $mysqli->prepare(
            "SELECT id_consulta_usuario, id_usuario, modalidade_pedido, entrada_original,
                    data_pedido, origem, fornecedor, status_resultado,
                    creditos_usuario_consumidos, credito_externo_consumido
             FROM {$pedidosUsuarios}
             ORDER BY data_pedido DESC
             LIMIT 100"
        );
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $historico[] = $row;
        }

        $stmt->close();
    }

    if ($view === 'conta' && $usuarioConsulta > 0) {
        $stmt = $mysqli->prepare(
            "SELECT id_consulta_usuario, id_usuario, modalidade_pedido, entrada_original,
                    data_pedido, origem, fornecedor, status_resultado,
                    creditos_usuario_consumidos, credito_externo_consumido
             FROM {$pedidosUsuarios}
             WHERE id_usuario = ?
             ORDER BY data_pedido DESC
             LIMIT 50"
        );
        $stmt->bind_param('i', $usuarioConsulta);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $historicoUsuario[] = $row;
        }

        $stmt->close();
    }

    if ($view === 'home' || $view === 'externos' || $view === 'adicionar_externo') {
        $result = $mysqli->query(
            "SELECT fornecedor, operacao, data_operacao, creditos_movimentados,
                    saldo_anterior, saldo_final, observacao
             FROM {$controleCreditoExterno}
             ORDER BY data_operacao DESC, id_movimento_externo DESC
             LIMIT 100"
        );

        while ($result && ($row = $result->fetch_assoc())) {
            $fornecedores[] = $row;
        }

        $result = $mysqli->query(
            "SELECT c.fornecedor, c.saldo_final
             FROM {$controleCreditoExterno} c
             INNER JOIN (
                SELECT fornecedor, MAX(id_movimento_externo) AS id_ultimo
                FROM {$controleCreditoExterno}
                GROUP BY fornecedor
             ) ult ON ult.id_ultimo = c.id_movimento_externo
             ORDER BY c.fornecedor"
        );

        while ($result && ($row = $result->fetch_assoc())) {
            $saldosExternos[] = $row;
        }
    }

    $mysqli->close();
} catch (Throwable $exception) {
    $erro = $erro ?: 'Não foi possível carregar os dados do painel: ' . $exception->getMessage();
}

if (function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    $demoPainel = bidmap_demo_painel();
    $erro = null;
    $metricas = $demoPainel['metricas'];
    $historico = $demoPainel['historico'];
    $historicoUsuario = $demoPainel['historicoUsuario'];
    $fornecedores = $demoPainel['fornecedores'];
    $saldosExternos = $demoPainel['saldosExternos'];
    $tipos = $demoPainel['tipos'];
    $custosOperacoes = $demoPainel['custosOperacoes'];
    $saldoUsuario = (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '100');
}

$titulos = [
    'home' => 'Painel de operações',
    'conta' => 'Conta do usuário',
    'consumo' => 'Consumo por tipo',
    'historico' => 'Histórico das consultas',
    'externos' => 'Créditos externos',
    'adicionar_externo' => 'Gerenciar saldo externo',
    'custos' => 'Tabela de custos',
];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard de Controle de Consultas</title>
    <link rel="stylesheet" href="css/painel_creditos.css?v=<?= filemtime(__DIR__ . '/css/painel_creditos.css'); ?>">
</head>
<body>
    <header class="admin-topbar">
        <div class="brand-dot">B</div>
        <strong>Dashboard de controle de consultas</strong>
        <nav>
            <a href="consultar_processos.php">Ir para ferramenta</a>
        </nav>
    </header>

    <?php if ($view !== 'home'): ?>
        <div class="admin-backbar">
            <a href="painel_creditos.php">Voltar para o painel de controle</a>
        </div>
    <?php endif; ?>

    <main class="admin-shell">
        <section class="hero">
            <p>Painel interno</p>
            <h1><?= painel_h($titulos[$view]); ?></h1>
            <span>Usuário admin logado: <?= painel_h($usuarioLogado); ?></span>
        </section>

        <?php if ($mensagem): ?>
            <div class="notice success"><?= painel_h($mensagem); ?></div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="notice error"><?= painel_h($erro); ?></div>
        <?php endif; ?>

        <?php if ($view === 'home'): ?>
            <section class="grid metrics">
                <article>
                    <span>Pedidos registrados</span>
                    <strong><?= painel_h($metricas['pedidos']); ?></strong>
                </article>
                <article>
                    <span>Créditos cobrados</span>
                    <strong><?= painel_h(painel_money($metricas['creditos_usuario'])); ?></strong>
                </article>
                <article>
                    <span>Créditos externos</span>
                    <strong><?= painel_h(painel_money($metricas['creditos_externos'])); ?></strong>
                </article>
                <article>
                    <span>Consultas web</span>
                    <strong><?= painel_h($metricas['consultas_web']); ?></strong>
                </article>
            </section>

            <section class="nav-grid">
                <a class="nav-card" href="<?= painel_h(painel_url('conta')); ?>">
                    <strong>Conta do usuário</strong>
                    <span>Consultar saldo, adicionar ou remover créditos de um usuário.</span>
                </a>
                <a class="nav-card" href="<?= painel_h(painel_url('historico')); ?>">
                    <strong>Histórico das consultas</strong>
                    <span>Ver quem requisitou, o que requisitou, origem, fornecedor e custos.</span>
                </a>
                <a class="nav-card" href="<?= painel_h(painel_url('externos')); ?>">
                    <strong>Créditos das plataformas externas</strong>
                    <span>Acompanhar consumo e saldo das integrações pagas.</span>
                </a>
                <a class="nav-card" href="<?= painel_h(painel_url('adicionar_externo')); ?>">
                    <strong>Gerenciar saldo externo</strong>
                    <span>Adicionar créditos comprados ou corrigir saldo em HubDev, Consulta de Processos e Processo Rápido.</span>
                </a>
                <a class="nav-card" href="<?= painel_h(painel_url('consumo')); ?>">
                    <strong>Consumo por tipo</strong>
                    <span>Resumo agregado por modalidade de pedido e fornecedor.</span>
                </a>
                <a class="nav-card" href="<?= painel_h(painel_url('custos')); ?>">
                    <strong>Tabela de custos</strong>
                    <span>Consultar quanto cada operação cobra do usuário e quanto consome nas plataformas externas.</span>
                </a>
                <a class="nav-card" href="consultar_processos.php">
                    <strong>Ferramenta de consulta</strong>
                    <span>Voltar para a tela de consulta de processos e dados pessoais.</span>
                </a>
            </section>
        <?php elseif ($view === 'conta'): ?>
            <section class="panel">
                <div class="panel-title">
                    <h2>Conta do usuário</h2>
                    <span>saldo e ajustes</span>
                </div>

                <form method="get" class="inline-form">
                    <input type="hidden" name="view" value="conta">
                    <label>
                        ID ou e-mail do usuário
                        <input type="text" name="usuario" value="<?= painel_h($usuarioBusca); ?>" placeholder="Ex.: 1478 ou email@dominio.com">
                    </label>
                    <button type="submit">Buscar</button>
                </form>

                <div class="balance-card">
                    <span>Saldo atual</span>
                    <strong><?= $saldoUsuario !== null ? painel_h(painel_money($saldoUsuario)) : 'Indisponível'; ?></strong>
                </div>

                <form method="post" class="credit-form">
                    <input type="hidden" name="view" value="conta">
                    <input type="hidden" name="usuario" value="<?= painel_h($usuarioConsulta); ?>">
                    <label>
                        Valor
                        <input type="number" name="valor" step="0.01" min="0.01" required>
                    </label>
                    <label>
                        Descrição
                        <input type="text" name="descricao" value="Ajuste manual no painel">
                    </label>
                    <div class="actions">
                        <button type="submit" name="acao" value="creditar">Adicionar crédito</button>
                        <button class="danger" type="submit" name="acao" value="debitar">Remover crédito</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-title">
                    <h2>Histórico desta conta</h2>
                    <span>últimas consultas do usuário selecionado</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Entrada</th>
                                <th>Origem</th>
                                <th>Fornecedor</th>
                                <th>Status</th>
                                <th>Créd. usuário</th>
                                <th>Créd. externo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historicoUsuario as $row): ?>
                                <tr>
                                    <td><?= painel_h(painel_data_br($row['data_pedido'])); ?></td>
                                    <td><?= painel_h($row['modalidade_pedido']); ?></td>
                                    <td><?= painel_h($row['entrada_original']); ?></td>
                                    <td><?= painel_h($row['origem']); ?></td>
                                    <td><?= painel_h($row['fornecedor'] ?: 'sem fornecedor'); ?></td>
                                    <td><span class="status"><?= painel_h($row['status_resultado']); ?></span></td>
                                    <td><?= painel_h(painel_money((float) $row['creditos_usuario_consumidos'])); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['credito_externo_consumido'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($historicoUsuario)): ?>
                                <tr><td colspan="8">Nenhuma consulta encontrada para este usuário.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'consumo'): ?>
            <section class="panel">
                <div class="panel-title">
                    <h2>Consumo por tipo</h2>
                    <span>resumo por modalidade e fornecedor</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Fornecedor</th>
                                <th>Total</th>
                                <th>Créd. usuário</th>
                                <th>Créd. externo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tipos as $row): ?>
                                <tr>
                                    <td><?= painel_h($row['modalidade_pedido']); ?></td>
                                    <td><?= painel_h($row['fornecedor']); ?></td>
                                    <td><?= painel_h($row['total']); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['creditos_usuario'])); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['creditos_externos'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($tipos)): ?>
                                <tr><td colspan="5">Nenhum consumo registrado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'historico'): ?>
            <section class="panel">
                <div class="panel-title">
                    <h2>Histórico das últimas consultas</h2>
                    <span>quem requisitou, o que requisitou e quanto custou</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Usuário</th>
                                <th>Tipo</th>
                                <th>Entrada</th>
                                <th>Origem</th>
                                <th>Fornecedor</th>
                                <th>Status</th>
                                <th>Créd. usuário</th>
                                <th>Créd. externo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $row): ?>
                                <tr>
                                    <td><?= painel_h(painel_data_br($row['data_pedido'])); ?></td>
                                    <td><?= painel_h($row['id_usuario']); ?></td>
                                    <td><?= painel_h($row['modalidade_pedido']); ?></td>
                                    <td><?= painel_h($row['entrada_original']); ?></td>
                                    <td><?= painel_h($row['origem']); ?></td>
                                    <td><?= painel_h($row['fornecedor'] ?: 'sem fornecedor'); ?></td>
                                    <td><span class="status"><?= painel_h($row['status_resultado']); ?></span></td>
                                    <td><?= painel_h(painel_money((float) $row['creditos_usuario_consumidos'])); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['credito_externo_consumido'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($historico)): ?>
                                <tr><td colspan="9">Nenhuma consulta registrada.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'custos'): ?>
            <section class="panel">
                <div class="panel-title">
                    <h2>Tabela de custos</h2>
                    <span>valores cobrados do usuário e consumidos em fornecedores externos</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Operação</th>
                                <th>Modalidade</th>
                                <th>Fornecedor</th>
                                <th>Custo interno BidMap</th>
                                <th>Custo externo fornecedor</th>
                                <th>Observação</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($custosOperacoes as $row): ?>
                                <?php $formId = 'cost-form-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) $row['chave']); ?>
                                <tr>
                                    <td><?= painel_h($row['operacao']); ?></td>
                                    <td><?= painel_h($row['modalidade_pedido']); ?></td>
                                    <td><?= painel_h($row['fornecedor']); ?></td>
                                    <td>
                                        <label class="sr-only" for="creditos-<?= painel_h($row['chave']); ?>">Créditos usuário</label>
                                        <input id="creditos-<?= painel_h($row['chave']); ?>" class="cost-input" form="<?= painel_h($formId); ?>" type="number" name="creditos_usuario" min="0" step="0.01" value="<?= painel_h(painel_money((float) $row['creditos_usuario'])); ?>">
                                    </td>
                                    <td>
                                        <label class="sr-only" for="externo-<?= painel_h($row['chave']); ?>">Custo externo</label>
                                        <input id="externo-<?= painel_h($row['chave']); ?>" class="cost-input" form="<?= painel_h($formId); ?>" type="number" name="custo_externo" min="0" step="0.01" value="<?= painel_h(painel_money((float) $row['custo_externo'])); ?>">
                                    </td>
                                    <td>
                                        <label class="sr-only" for="obs-<?= painel_h($row['chave']); ?>">Observação</label>
                                        <input id="obs-<?= painel_h($row['chave']); ?>" class="cost-input wide" form="<?= painel_h($formId); ?>" type="text" name="observacao" value="<?= painel_h($row['observacao']); ?>">
                                    </td>
                                    <td>
                                        <form id="<?= painel_h($formId); ?>" method="post">
                                            <input type="hidden" name="view" value="custos">
                                            <input type="hidden" name="acao" value="atualizar_custo">
                                            <input type="hidden" name="chave" value="<?= painel_h($row['chave']); ?>">
                                            <button class="small-save" type="submit">Salvar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($custosOperacoes)): ?>
                                <tr><td colspan="7">Nenhum custo configurado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'externos'): ?>
            <section class="grid metrics external-balances">
                <?php foreach ($saldosExternos as $row): ?>
                    <article>
                        <span><?= painel_h($row['fornecedor']); ?></span>
                        <strong><?= painel_h(painel_money((float) $row['saldo_final'])); ?></strong>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($saldosExternos)): ?>
                    <article>
                        <span>Sem saldo registrado</span>
                        <strong>0.00</strong>
                    </article>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-title">
                    <h2>Créditos das plataformas externas</h2>
                    <span><a href="<?= painel_h(painel_url('adicionar_externo')); ?>">Gerenciar saldo externo</a></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Fornecedor</th>
                                <th>Tipo</th>
                                <th>Créditos</th>
                                <th>Saldo anterior</th>
                                <th>Saldo posterior</th>
                                <th>Observação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fornecedores as $row): ?>
                                <tr>
                                    <td><?= painel_h(painel_data_br($row['data_operacao'])); ?></td>
                                    <td><?= painel_h($row['fornecedor']); ?></td>
                                    <td><?= painel_h($row['operacao']); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['creditos_movimentados'])); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['saldo_anterior'])); ?></td>
                                    <td><?= painel_h(painel_money((float) $row['saldo_final'])); ?></td>
                                    <td><?= painel_h($row['observacao']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fornecedores)): ?>
                                <tr><td colspan="7">Nenhuma movimentação externa registrada.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'adicionar_externo'): ?>
            <section class="grid two manage-external">
                <article class="panel">
                    <div class="panel-title">
                        <h2>Adicionar ou remover créditos</h2>
                        <span>movimenta o saldo interno da plataforma</span>
                    </div>

                    <form method="post" class="credit-form">
                        <input type="hidden" name="view" value="adicionar_externo">
                        <label>
                            Fornecedor
                            <select name="fornecedor" required>
                                <?php foreach (painel_fornecedores_padrao() as $fornecedor): ?>
                                    <option value="<?= painel_h($fornecedor); ?>"><?= painel_h($fornecedor); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Valor para adicionar/remover
                            <input type="number" name="valor" step="0.01" min="0.01" placeholder="Ex.: 2000.00" required>
                        </label>
                        <label>
                            Observação
                            <input type="text" name="descricao" value="Compra de créditos externos">
                        </label>
                        <div class="actions">
                            <button type="submit" name="acao" value="creditar_externo">Adicionar créditos</button>
                            <button class="danger" type="submit" name="acao" value="debitar_externo">Registrar débito</button>
                        </div>
                    </form>

                    <p class="form-help">
                        Use "Adicionar créditos" quando comprar créditos em uma plataforma externa.
                        Use "Registrar débito" apenas para correções manuais, pois o consumo das consultas já é registrado pelo sistema.
                    </p>
                </article>

                <article class="panel">
                    <div class="panel-title">
                        <h2>Definir saldo exato</h2>
                        <span>corrige o saldo para o valor real</span>
                    </div>

                    <form method="post" class="credit-form">
                        <input type="hidden" name="view" value="adicionar_externo">
                        <label>
                            Fornecedor
                            <select name="fornecedor" required>
                                <?php foreach (painel_fornecedores_padrao() as $fornecedor): ?>
                                    <option value="<?= painel_h($fornecedor); ?>"><?= painel_h($fornecedor); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Novo saldo total
                            <input type="number" name="saldo_final" step="0.01" placeholder="Ex.: 2500.00" required>
                        </label>
                        <label>
                            Observação
                            <input type="text" name="descricao" value="Ajuste conforme saldo real da plataforma">
                        </label>
                        <div class="actions">
                            <button class="secondary" type="submit" name="acao" value="ajustar_externo">Definir saldo exato</button>
                        </div>
                    </form>

                    <p class="form-help">
                        Use quando o saldo do painel estiver diferente do saldo mostrado no fornecedor.
                        O sistema calcula a diferença e grava uma movimentação de ajuste.
                    </p>
                </article>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>


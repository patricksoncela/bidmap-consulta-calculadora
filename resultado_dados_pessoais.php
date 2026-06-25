<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/services/DocumentoService.php';
require_once __DIR__ . '/database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/credit_modal.php';
require_once __DIR__ . '/helpers/account_menu.php';

bidmap_require_login_for_creditos();

function view_env_bool(string $key, bool $default = false): bool
{
    $value = bidmap_env($key);

    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function view_database_connection(): mysqli
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

function view_usuario_credito_service(): UsuarioCreditoService
{
    static $service = null;

    if ($service instanceof UsuarioCreditoService) {
        return $service;
    }

    if (!view_env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false)) {
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

function view_creditos_disponiveis(): float
{
    try {
        $service = view_usuario_credito_service();
        return $service->getSaldo($service->getUsuarioId());
    } catch (Throwable $exception) {
        return 0.0;
    }
}

function view_format_creditos(float $creditos): string
{
    return number_format($creditos, 2, '.', '');
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function value_or_dash($value): string
{
    if ($value === null || trim((string) $value) === '') {
        return 'Não informado';
    }

    return h((string) $value);
}

function value_or_nao($value): string
{
    if ($value === null || trim((string) $value) === '') {
        return 'Não';
    }

    return h((string) $value);
}

function money_or_dash($value): string
{
    if ($value === null || trim((string) $value) === '') {
        return 'Não informado';
    }

    $value = trim((string) $value);

    if (str_contains($value, '*')) {
        return 'Não informado';
    }

    $normalized = preg_replace('/[^\d,.-]/', '', $value) ?? '';
    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');

    if ($lastComma !== false && $lastDot !== false) {
        $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandSeparator, '', $normalized);
        $normalized = str_replace($decimalSeparator, '.', $normalized);
    } elseif ($lastComma !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } elseif ($lastDot !== false) {
        $parts = explode('.', $normalized);

        if (count($parts) > 2 && strlen((string) end($parts)) === 3) {
            $normalized = str_replace('.', '', $normalized);
        }
    }

    if (!is_numeric($normalized)) {
        return h($value);
    }

    return h('R$ ' . number_format((float) $normalized, 2, ',', '.'));
}

function format_documento_resultado(?string $value, ?string $tipo): string
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

function view_resultado_salvo(mysqli $mysqli, int $consultaId, ?string $tipoConsulta): ?array
{
    if ($consultaId <= 0) {
        return null;
    }

    $consulta = (new ConsultaExternaModel($mysqli))->buscarPorId($consultaId);

    if (!$consulta || ($consulta['status_resultado'] ?? '') !== 'sucesso') {
        return null;
    }

    $modalidadeEsperada = $tipoConsulta === 'cnpj' ? 'dados_cnpj' : 'dados_cpf';

    if (($consulta['modalidade_pedido'] ?? '') !== $modalidadeEsperada) {
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

function view_resultado_por_pedido(mysqli $mysqli, int $pedidoId, ?string $tipoConsulta): ?array
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

    $usuarioAtual = view_usuario_credito_service()->getUsuarioId();
    if ((int) ($pedido['id_usuario'] ?? 0) !== $usuarioAtual) {
        return [
            'ok' => false,
            'message' => 'Pedido nao pertence ao usuario atual.',
        ];
    }

    $status = strtolower((string) ($pedido['status_resultado'] ?? ''));
    $consultaId = (int) ($pedido['id_consulta'] ?? 0);

    if ($status === 'sucesso' && $consultaId > 0) {
        $resultado = view_resultado_salvo($mysqli, $consultaId, $tipoConsulta);

        if ($resultado) {
            $resultado['pedido_id'] = $pedidoId;
            return $resultado;
        }

        return [
            'ok' => false,
            'message' => 'Resultado concluido, mas os dados salvos nao foram encontrados.',
        ];
    }

    if ($status === 'pendente') {
        return [
            'ok' => true,
            'pendente' => true,
            'origem' => 'fila',
            'pedido_id' => $pedidoId,
            'consulta_id' => $consultaId ?: null,
            'dados' => [],
            'message' => (string) ($pedido['mensagem_resultado'] ?? 'Estamos processando os dados. Esta pagina sera atualizada automaticamente.'),
            'saldo_anterior' => isset($pedido['saldo_anterior_usuario']) ? (float) $pedido['saldo_anterior_usuario'] : null,
            'saldo_posterior' => isset($pedido['saldo_posterior_usuario']) ? (float) $pedido['saldo_posterior_usuario'] : null,
        ];
    }

    return [
        'ok' => false,
        'message' => (string) ($pedido['mensagem_resultado'] ?? 'Nao foi possivel concluir esta consulta.'),
    ];
}

function view_refresh_url_dados(array $resultado, string $entrada, ?string $tipoConsulta): string
{
    $params = [
        'acao' => 'dados_pessoais',
        'tipo_consulta' => $tipoConsulta,
        'q' => $entrada,
    ];

    $pedidoId = (int) ($resultado['pedido_id'] ?? 0);
    if ($pedidoId > 0) {
        $params['pedido_id'] = $pedidoId;
    }

    return 'resultado_dados_pessoais.php?' . http_build_query(array_filter(
        $params,
        static fn($value) => $value !== null && $value !== ''
    ));
}

$creditos = view_creditos_disponiveis();
$entrada = $_GET['q'] ?? '';
$tipoConsulta = $_GET['tipo_consulta'] ?? null;
$consultaIdHistorico = (int) ($_GET['consulta_id'] ?? 0);
$pedidoIdAcompanhamento = (int) ($_GET['pedido_id'] ?? 0);
$abrindoHistorico = ($_GET['origem'] ?? '') === 'historico';
$resultado = null;
$erro = null;

try {
    $mysqli = view_database_connection();

    if ($pedidoIdAcompanhamento > 0) {
        $resultado = view_resultado_por_pedido($mysqli, $pedidoIdAcompanhamento, $tipoConsulta);
    } elseif ($abrindoHistorico) {
        $resultado = view_resultado_salvo($mysqli, $consultaIdHistorico, $tipoConsulta);
    }

    if ($abrindoHistorico && !$resultado) {
        $resultado = [
            'ok' => false,
            'message' => 'Não foi possível abrir esta consulta salva.',
        ];
    } elseif (!$resultado) {
        $service = new DocumentoService($mysqli, null, view_usuario_credito_service());
        $resultado = $service->consultarDadosPessoais($entrada, $tipoConsulta, ($_GET['atualizar'] ?? '') === '1');
    }

    $mysqli->close();

    if (!$resultado['ok']) {
        $erro = $resultado['message'] ?? 'Não foi possível consultar os dados.';
    }
} catch (Throwable $exception) {
    $erro = $exception->getMessage();
}

$raw = $resultado['dados'] ?? [];
$dados = is_array($raw) && isset($raw['result']) && is_array($raw['result']) ? $raw['result'] : $raw;
$consultaPendente = !$erro && !empty($resultado['pendente']);
$refreshUrl = $consultaPendente ? view_refresh_url_dados($resultado, (string) $entrada, $tipoConsulta) : '';
$tipoLabel = strtoupper((string) $tipoConsulta);
$documento = $dados['documento'] ?? $dados['numero_de_inscricao'] ?? preg_replace('/\D+/', '', $entrada);
$nomePrincipal = $dados['nomeCompleto'] ?? $dados['nome'] ?? 'Dados pessoais';
$documentoFormatado = format_documento_resultado((string) $documento, $tipoConsulta);
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime('css/style.css'); ?>">
    <link rel="stylesheet" href="css/consultar_processos.css?v=<?php echo filemtime('css/consultar_processos.css'); ?>">
    <script src="https://kit.fontawesome.com/1ad52ad40a.js" crossorigin="anonymous"></script>
    <title>Dados Pessoais</title>
    <?php if ($consultaPendente): ?>
        <meta http-equiv="refresh" content="5;url=<?= h($refreshUrl); ?>">
    <?php endif; ?>
</head>

<body>
    <div id="main">
        <?php include_once 'header/header.php'; ?>

        <main class="resultado-page dados-page">
            <section class="resultado-toolbar" aria-label="Navegação da consulta">
                <div class="resultado-breadcrumb">
                    <a href="consultar_processos.php">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        Consulta de processos
                    </a>
                    <span></span>
                    <strong>Dados pessoais</strong>
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
                    <strong><?= h(view_format_creditos($creditos)); ?></strong>
                    Créditos
                    <span class="credit-add">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </span>
                    </button>
                </div>
            </section>

            <section class="dados-wrap">
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
                            <p><?= h((string) ($resultado['message'] ?? 'Estamos processando os dados. Esta página será atualizada automaticamente.')); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <header class="person-summary dados-summary">
                        <div>
                            <h1><?= h($nomePrincipal); ?></h1>
                            <p>
                                <span><?= h($tipoLabel); ?></span>
                                <?= h($documentoFormatado); ?>
                            </p>
                        </div>
                    </header>

                    <?php if ($tipoConsulta === 'cpf'): ?>
                        <section class="dados-grid">
                            <article class="dados-card">
                                <h2><i class="fa-regular fa-id-card" aria-hidden="true"></i> Cadastro</h2>
                                <dl>
                                    <div><dt>Código pessoa</dt><dd><?= value_or_dash($dados['codigoPessoa'] ?? null); ?></dd></div>
                                    <div><dt>CPF</dt><dd><?= h($documentoFormatado); ?></dd></div>
                                    <div><dt>Nome completo</dt><dd><?= value_or_dash($dados['nomeCompleto'] ?? null); ?></dd></div>
                                    <div><dt>Gênero</dt><dd><?= value_or_dash($dados['genero'] ?? null); ?></dd></div>
                                    <div><dt>Data de nascimento</dt><dd><?= value_or_dash($dados['dataDeNascimento'] ?? null); ?></dd></div>
                                    <div><dt>Idade</dt><dd><?= value_or_dash(isset($dados['anos']) ? (string) $dados['anos'] : null); ?></dd></div>
                                    <div><dt>Zodíaco</dt><dd><?= value_or_dash($dados['zodiaco'] ?? null); ?></dd></div>
                                    <div><dt>Nome da mãe</dt><dd><?= value_or_dash($dados['nomeDaMae'] ?? null); ?></dd></div>
                                    <div><dt>Status cadastral</dt><dd><?= value_or_dash($dados['statusCadastral'] ?? null); ?></dd></div>
                                    <div><dt>Data do status cadastral</dt><dd><?= value_or_dash($dados['dataStatusCadastral'] ?? null); ?></dd></div>
                                    <div><dt>Salário estimado</dt><dd><?= money_or_dash($dados['salarioEstimado'] ?? null); ?></dd></div>
                                    <div><dt>Última atualização</dt><dd><?= value_or_dash($dados['lastUpdate'] ?? null); ?></dd></div>
                                </dl>
                            </article>

                            <article class="dados-card">
                                <h2><i class="fa-solid fa-phone" aria-hidden="true"></i> Telefones</h2>
                                <?php if (!empty($dados['listaTelefones']) && is_array($dados['listaTelefones'])): ?>
                                    <div class="dados-list">
                                        <?php foreach ($dados['listaTelefones'] as $telefone): ?>
                                            <div>
                                                <strong><?= value_or_dash($telefone['telefoneComDDD'] ?? null); ?></strong>
                                                <span>Tipo: <?= value_or_dash($telefone['tipoTelefone'] ?? null); ?></span>
                                                <span>Operadora: <?= value_or_dash($telefone['operadora'] ?? null); ?></span>
                                                <span>WhatsApp: <?= value_or_nao($telefone['whatsApp'] ?? null); ?></span>
                                                <span>Telemarketing bloqueado: <?= value_or_nao($telefone['telemarketingBloqueado'] ?? null); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="dados-empty">Nenhum telefone retornado.</p>
                                <?php endif; ?>
                            </article>

                            <article class="dados-card">
                                <h2><i class="fa-regular fa-envelope" aria-hidden="true"></i> Emails</h2>
                                <?php if (!empty($dados['listaEmails']) && is_array($dados['listaEmails'])): ?>
                                    <div class="dados-list">
                                        <?php foreach ($dados['listaEmails'] as $email): ?>
                                            <div>
                                                <strong><?= value_or_dash($email['enderecoEmail'] ?? null); ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="dados-empty">Nenhum email retornado.</p>
                                <?php endif; ?>
                            </article>

                            <article class="dados-card wide">
                                <h2><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Endereços</h2>
                                <?php if (!empty($dados['listaEnderecos']) && is_array($dados['listaEnderecos'])): ?>
                                    <div class="address-list">
                                        <?php foreach ($dados['listaEnderecos'] as $endereco): ?>
                                            <div>
                                                <strong><?= value_or_dash(trim(($endereco['logradouro'] ?? '') . ' ' . ($endereco['numero'] ?? ''))); ?></strong>
                                                <span>
                                                    <?= value_or_dash(trim(($endereco['bairro'] ?? '') . ' - ' . ($endereco['cidade'] ?? '') . '/' . ($endereco['uf'] ?? ''))); ?>
                                                </span>
                                                <span>Complemento: <?= value_or_dash($endereco['complemento'] ?? null); ?></span>
                                                <small>CEP <?= value_or_dash($endereco['cep'] ?? null); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="dados-empty">Nenhum endereço retornado.</p>
                                <?php endif; ?>
                            </article>
                        </section>
                    <?php else: ?>
                        <section class="dados-grid">
                            <article class="dados-card">
                                <h2><i class="fa-regular fa-building" aria-hidden="true"></i> Empresa</h2>
                                <dl>
                                    <div><dt>Razão social</dt><dd><?= value_or_dash($dados['nome'] ?? null); ?></dd></div>
                                    <div><dt>Fantasia</dt><dd><?= value_or_dash($dados['fantasia'] ?? null); ?></dd></div>
                                    <div><dt>Situação</dt><dd><?= value_or_dash($dados['situacao'] ?? null); ?></dd></div>
                                    <div><dt>Abertura</dt><dd><?= value_or_dash($dados['abertura'] ?? null); ?></dd></div>
                                    <div><dt>Natureza jurídica</dt><dd><?= value_or_dash($dados['natureza_juridica'] ?? null); ?></dd></div>
                                    <div><dt>CNPJ</dt><dd><?= h($documentoFormatado); ?></dd></div>
                                    <div><dt>Tipo unidade</dt><dd><?= value_or_dash($dados['tipo'] ?? null); ?></dd></div>
                                    <div><dt>Situação cadastral</dt><dd><?= value_or_dash(trim(($dados['situacao'] ?? '') . (!empty($dados['dt_situacao_cadastral']) ? ' desde ' . $dados['dt_situacao_cadastral'] : ''))); ?></dd></div>
                                    <div><dt>Situação especial</dt><dd><?= value_or_dash($dados['situacao_especial'] ?? null); ?></dd></div>
                                    <div><dt>Porte</dt><dd><?= value_or_dash($dados['porte'] ?? null); ?></dd></div>
                                    <div><dt>Opção pelo Simples</dt><dd><?= value_or_nao($dados['opcao_pelo_simples'] ?? $dados['simples'] ?? null); ?></dd></div>
                                    <div><dt>Opção pelo MEI</dt><dd><?= value_or_nao($dados['opcao_pelo_mei'] ?? $dados['mei'] ?? null); ?></dd></div>
                                    <div><dt>Capital social</dt><dd><?= money_or_dash($dados['capital_social'] ?? null); ?></dd></div>
                                    <div><dt>Ente federativo responsável</dt><dd><?= value_or_dash($dados['entidade_federativo_responsavel'] ?? null); ?></dd></div>
                                </dl>
                            </article>

                            <article class="dados-card">
                                <h2><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> Endereço</h2>
                                <dl>
                                    <div><dt>Logradouro</dt><dd><?= value_or_dash($dados['logradouro'] ?? null); ?></dd></div>
                                    <div><dt>Número</dt><dd><?= value_or_dash($dados['numero'] ?? null); ?></dd></div>
                                    <div><dt>Bairro</dt><dd><?= value_or_dash($dados['bairro'] ?? null); ?></dd></div>
                                    <div><dt>Município</dt><dd><?= value_or_dash($dados['municipio'] ?? null); ?></dd></div>
                                    <div><dt>UF</dt><dd><?= value_or_dash($dados['uf'] ?? null); ?></dd></div>
                                    <div><dt>CEP</dt><dd><?= value_or_dash($dados['cep'] ?? null); ?></dd></div>
                                    <div><dt>Complemento</dt><dd><?= value_or_dash($dados['complemento'] ?? null); ?></dd></div>
                                    <div><dt>Email</dt><dd><?= value_or_dash($dados['email'] ?? null); ?></dd></div>
                                    <div><dt>Telefone</dt><dd><?= value_or_dash($dados['telefone'] ?? null); ?></dd></div>
                                </dl>
                            </article>

                            <article class="dados-card wide">
                                <h2><i class="fa-solid fa-briefcase" aria-hidden="true"></i> Atividade principal</h2>
                                <p class="dados-text">
                                    <?= value_or_dash($dados['atividade_principal']['code'] ?? null); ?>
                                    <?= value_or_dash($dados['atividade_principal']['text'] ?? null); ?>
                                </p>
                            </article>

                            <?php if (!empty($dados['atividades_secundarias']) && is_array($dados['atividades_secundarias'])): ?>
                                <article class="dados-card wide">
                                    <h2><i class="fa-solid fa-list-check" aria-hidden="true"></i> Atividades secundárias</h2>
                                    <div class="dados-list">
                                        <?php foreach (array_slice($dados['atividades_secundarias'], 0, 8) as $atividade): ?>
                                            <div>
                                                <strong><?= value_or_dash($atividade['code'] ?? null); ?></strong>
                                                <span><?= value_or_dash($atividade['text'] ?? null); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endif; ?>

                            <?php if (!empty($dados['quadro_socios']) && is_array($dados['quadro_socios'])): ?>
                                <article class="dados-card wide">
                                    <h2><i class="fa-solid fa-users" aria-hidden="true"></i> Quadro societário</h2>
                                    <div class="dados-list">
                                        <?php foreach ($dados['quadro_socios'] as $socio): ?>
                                            <div>
                                                <strong><?= value_or_dash(is_scalar($socio) ? (string) $socio : ($socio['nome'] ?? null)); ?></strong>
                                                <?php if (is_array($socio)): ?>
                                                    <span><?= value_or_dash($socio['qualificacao'] ?? $socio['tipo'] ?? null); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
        <?php bidmap_render_credit_modal((float) $creditos); ?>
    </div>
</body>

</html>

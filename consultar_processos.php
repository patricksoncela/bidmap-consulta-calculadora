<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/database/consulta_tables.php';
require_once __DIR__ . '/services/UsuarioCreditoService.php';
require_once __DIR__ . '/database/models/TipoPedidoModel.php';
require_once __DIR__ . '/database/models/CustoConsultaModel.php';
require_once __DIR__ . '/helpers/processos_actions.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/account_menu.php';
require_once __DIR__ . '/helpers/pdf_download_token.php';

bidmap_require_login_for_creditos();

$historicoCompleto = ($_GET['historico'] ?? '') === '1';
$historicoLimite = $historicoCompleto ? 10 : 5;
$historicoPagina = $historicoCompleto ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
$historicoBusca = $historicoCompleto ? trim((string) ($_GET['busca'] ?? '')) : '';
$historicoFiltroDocumento = $historicoCompleto ? strtolower(trim((string) ($_GET['documento'] ?? ''))) : '';
$historicoFiltroModalidade = $historicoCompleto ? strtolower(trim((string) ($_GET['modalidade'] ?? ''))) : '';
$historicoFiltroStatus = $historicoCompleto ? strtolower(trim((string) ($_GET['status'] ?? ''))) : '';
$historicoOffset = ($historicoPagina - 1) * $historicoLimite;
$historicoTotal = 0;
$historicoConsultas = [];
$consultasRecentes = [];
$historicoErro = null;
$creditosErro = null;
$consultaErro = trim((string) ($_GET['consulta_erro'] ?? ''));
$pdfProcessandoMensagem = '';

$historicoDocumentosPermitidos = ['', 'cpf', 'cnpj', 'cnj'];
$historicoModalidadesPermitidas = ['', 'consulta_processos', 'dados', 'detalhes_processo', 'pdf_processo'];
$historicoStatusPermitidos = ['', 'sucesso', 'pendente', 'erro', 'estornado', 'saldo_insuficiente', 'expirada'];

if (!in_array($historicoFiltroDocumento, $historicoDocumentosPermitidos, true)) {
    $historicoFiltroDocumento = '';
}

if (!in_array($historicoFiltroModalidade, $historicoModalidadesPermitidas, true)) {
    $historicoFiltroModalidade = '';
}

if (!in_array($historicoFiltroStatus, $historicoStatusPermitidos, true)) {
    $historicoFiltroStatus = '';
}

if ($consultaErro === '' && (string) ($_GET['pdf_status'] ?? '') === 'error') {
    $consultaErro = trim((string) ($_GET['pdf_message'] ?? 'Não foi possível baixar o PDF.'));
}

if ((string) ($_GET['pdf_status'] ?? '') === 'processing') {
    $pdfProcessandoMensagem = 'Consultando dados e criando PDF completo. A página será atualizada automaticamente quando o PDF estiver disponível.';
}

$actionToken = processos_action_token();
$creditosDetalhesProcesso = 3.0;
$creditosBuscarProcessosCpf = 5.0;
$creditosBuscarProcessosCnpj = 5.0;
$creditosDadosPessoaisCpf = 3.0;
$creditosDadosPessoaisCnpj = 3.0;
$creditosPdfProcesso = 12.0;

function processos_saldo_sessao(): ?float
{
    if (!isset($_SESSION['cliente']) || !is_array($_SESSION['cliente'])) {
        return null;
    }

    foreach (['saldo', 'creditos', 'credito', 'saldo_creditos'] as $key) {
        if (!array_key_exists($key, $_SESSION['cliente'])) {
            continue;
        }

        $value = $_SESSION['cliente'][$key];

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            $normalized = preg_replace('/[^\d,.-]/', '', $normalized) ?? $normalized;

            if (strpos($normalized, ',') !== false) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            }

            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }
    }

    return null;
}

function processos_creditos_disponiveis(): float
{
    global $creditosErro;

    if (filter_var(bidmap_env('DADOS_PESSOAIS_SKIP_CREDITOS', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        $saldoSessao = processos_saldo_sessao();

        if ($saldoSessao !== null) {
            return $saldoSessao;
        }

        return (float) bidmap_env('DADOS_PESSOAIS_TEST_SALDO', '0');
    }

    try {
        $service = processos_usuario_credito_service();
        return $service->getSaldo($service->getUsuarioId());
    } catch (Throwable $exception) {
        $creditosErro = $exception->getMessage();
        return 0.0;
    }
}

function processos_usuario_credito_service(): UsuarioCreditoService
{
    static $service = null;

    if (!$service instanceof UsuarioCreditoService) {
        $service = new UsuarioCreditoService();
    }

    return $service;
}

function processos_creditos_checkout_url(int $valor): string
{
    if (function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
        return '#';
    }

    $fallbacks = [
        25 => '#',
        50 => '#',
        100 => '#',
    ];

    $fallback = $fallbacks[$valor] ?? '#';
    $url = trim((string) bidmap_env('BIDMAP_CHECKOUT_CREDITOS_' . $valor, $fallback));

    return $url !== '' && $url !== '#' ? $url : $fallback;
}

function processos_creditos_formatar(float $creditos): string
{
    return number_format($creditos, 2, '.', '');
}

function processos_usuario_id(): int
{
    try {
        return processos_usuario_credito_service()->getUsuarioId();
    } catch (Throwable $exception) {
        if (filter_var(bidmap_env('DADOS_PESSOAIS_SKIP_CREDITOS', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $fallback = (int) bidmap_env('DADOS_PESSOAIS_TEST_USER_ID', '0');

            if ($fallback > 0) {
                return $fallback;
            }
        }

        throw $exception;
    }
}

$creditos = processos_creditos_disponiveis();
$checkoutCreditos = [
    25 => processos_creditos_checkout_url(25),
    50 => processos_creditos_checkout_url(50),
    100 => processos_creditos_checkout_url(100),
];

function processos_historico_return_url(
    bool $historicoCompleto,
    int $historicoPagina,
    string $historicoBusca,
    string $historicoFiltroDocumento,
    string $historicoFiltroModalidade,
    string $historicoFiltroStatus
): string {
    $params = [];

    if ($historicoCompleto) {
        $params['historico'] = 1;

        if ($historicoPagina > 1) {
            $params['page'] = $historicoPagina;
        }

        if ($historicoBusca !== '') {
            $params['busca'] = $historicoBusca;
        }

        if ($historicoFiltroDocumento !== '') {
            $params['documento'] = $historicoFiltroDocumento;
        }

        if ($historicoFiltroModalidade !== '') {
            $params['modalidade'] = $historicoFiltroModalidade;
        }

        if ($historicoFiltroStatus !== '') {
            $params['status'] = $historicoFiltroStatus;
        }
    }

    return 'consultar_processos.php' . ($params ? '?' . http_build_query($params) : '') . '#historico-consultas';
}

function processos_db(): mysqli
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

function processos_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    $refs = [$types];

    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function processos_modalidade_label(?string $modalidade): string
{
    $labels = [
        'dados_cpf' => 'Dados por CPF',
        'dados_cnpj' => 'Dados por CNPJ',
        'consulta_processos' => 'Busca de Processos',
        'detalhes_processo' => 'Detalhes do processo',
        'processo' => 'Processo',
        'pdf_processo' => 'PDF do processo',
    ];

    return $labels[$modalidade] ?? ucfirst(str_replace('_', ' ', (string) $modalidade));
}

function processos_status_label(?string $status): string
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

function processos_historico_status(array $item): string
{
    $status = strtolower((string) ($item['status_resultado'] ?? 'pendente'));
    $validade = trim((string) ($item['data_validade'] ?? ''));

    if (($item['modalidade_pedido'] ?? '') === 'pdf_processo' && $status === 'sucesso') {
        $linkPdf = trim((string) ($item['link_pdf_cliente'] ?? ''));
        $consultaId = (int) ($item['id_consulta'] ?? 0);
        $temArquivoLocal = processos_pdf_local_existe($linkPdf);
        $temDownloadRemoto = bidmap_pdf_download_enabled() && $linkPdf !== '' && $consultaId > 0;

        if (!$temArquivoLocal && !$temDownloadRemoto) {
            return 'erro';
        }
    }

    if ($status === 'sucesso' && $validade !== '') {
        $timestamp = strtotime($validade);

        if ($timestamp !== false && $timestamp < time()) {
            return 'expirada';
        }
    }

    return $status;
}

function processos_pdf_storage_dirs(): array
{
    $path = trim((string) bidmap_env('PROCESSO_RAPIDO_STORAGE_DIR', 'storage/processos_pdf'));

    if ($path === '') {
        $path = 'storage/processos_pdf';
    }

    if (preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $path)) {
        return [$path];
    }

    $relativePath = ltrim($path, '/\\');
    $dirs = [
        __DIR__ . DIRECTORY_SEPARATOR . $relativePath,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath,
    ];

    return array_values(array_unique($dirs));
}

function processos_pdf_local_existe(string $path): bool
{
    $path = trim($path);

    if ($path === '') {
        return false;
    }

    if (is_file($path) && filesize($path) > 0) {
        return true;
    }

    $normalized = str_replace('\\', '/', $path);
    $markers = [
        '/storage/processos_pdf/',
        'storage/processos_pdf/',
    ];

    foreach ($markers as $marker) {
        $position = strpos($normalized, $marker);

        if ($position === false) {
            continue;
        }

        $relative = substr($normalized, $position + strlen($marker));

        foreach (processos_pdf_storage_dirs() as $storageDir) {
            $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));

            if (is_file($candidate) && filesize($candidate) > 0) {
                return true;
            }
        }
    }

    $basename = basename($normalized);

    if ($basename !== '' && preg_match('/\.pdf$/i', $basename)) {
        foreach (processos_pdf_storage_dirs() as $storageDir) {
            $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $basename;

            if (is_file($candidate) && filesize($candidate) > 0) {
                return true;
            }
        }
    }

    return false;
}

function processos_formatar_entrada(?string $valor, ?string $modalidade): string
{
    $valor = trim((string) $valor);
    $digitos = preg_replace('/\D+/', '', $valor);

    if ($digitos === '') {
        return $valor;
    }

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

function processos_historico_url(array $item): string
{
    $modalidade = $item['modalidade_pedido'] ?? '';
    $entrada = $item['entrada_original'] ?: $item['entrada_normalizada'];
    $digitos = preg_replace('/\D+/', '', $entrada);

    if ($modalidade === 'dados_cpf') {
        return 'resultado_dados_pessoais.php?' . http_build_query([
            'tipo_consulta' => 'cpf',
            'q' => $entrada,
            'acao' => 'dados_pessoais',
            'consulta_id' => (int) ($item['id_consulta'] ?? 0),
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'dados_cnpj') {
        return 'resultado_dados_pessoais.php?' . http_build_query([
            'tipo_consulta' => 'cnpj',
            'q' => $entrada,
            'acao' => 'dados_pessoais',
            'consulta_id' => (int) ($item['id_consulta'] ?? 0),
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'consulta_processos' && strlen($digitos) === 11) {
        return 'resultado_processos_documento.php?' . http_build_query([
            'tipo_consulta' => 'cpf',
            'q' => $entrada,
            'acao' => 'buscar_processos',
            'consulta_id' => (int) ($item['id_consulta'] ?? 0),
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'consulta_processos' && strlen($digitos) === 14) {
        return 'resultado_processos_documento.php?' . http_build_query([
            'tipo_consulta' => 'cnpj',
            'q' => $entrada,
            'acao' => 'buscar_processos',
            'consulta_id' => (int) ($item['id_consulta'] ?? 0),
            'origem' => 'historico',
        ]);
    }

    if ($modalidade === 'pdf_processo' && strlen($digitos) === 20) {
        return 'api/processos/documentos/pdf.php?' . http_build_query([
            'consulta_id' => (int) ($item['id_consulta'] ?? 0),
            'numero' => $entrada,
            'origem' => 'historico',
            'return' => 'consultar_processos.php#historico-consultas',
            'processos_action_token' => processos_action_token(),
        ]);
    }

    $params = [
        'q' => $entrada,
        'origem' => 'historico',
    ];

    if (!empty($item['id_consulta'])) {
        $params['consulta_id'] = (int) $item['id_consulta'];
    }

    return 'detalhe_processo.php?' . http_build_query($params);
}

function processos_historico_tipo_label(array $item): string
{
    $modalidade = (string) ($item['modalidade_pedido'] ?? '');
    $entrada = $item['entrada_original'] ?: $item['entrada_normalizada'];
    $digitos = preg_replace('/\D+/', '', (string) $entrada);

    if ($modalidade === 'consulta_processos') {
        if (strlen($digitos) === 11) {
            return 'Processos por CPF';
        }

        if (strlen($digitos) === 14) {
            return 'Processos por CNPJ';
        }

        return 'Busca de Processos';
    }

    return processos_modalidade_label($modalidade);
}

function processos_historico_primeiro_array(array $dados, array $keys): ?array
{
    foreach ($keys as $key) {
        $value = $dados;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }

            $value = $value[$part];
        }

        if (is_array($value)) {
            return $value;
        }
    }

    return null;
}

function processos_historico_primeiro_valor(array $dados, array $keys): string
{
    foreach ($keys as $key) {
        $value = $dados;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }

            $value = $value[$part];
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return '';
}

function processos_historico_lista_processos(array $dados): array
{
    foreach (['lawsuits', 'processos', 'items', 'results', 'data.lawsuits', 'data.processos', 'data.items', 'result.lawsuits', 'result.processos', 'result.items'] as $key) {
        $lista = processos_historico_primeiro_array($dados, [$key]);

        if ($lista !== null && isset($lista[0]) && is_array($lista[0])) {
            return $lista;
        }
    }

    return [];
}

function processos_historico_nome_documento(array $processos, string $documento): string
{
    $documento = preg_replace('/\D+/', '', $documento);

    if ($documento === '') {
        return '';
    }

    foreach ($processos as $processo) {
        if (!is_array($processo) || empty($processo['parties']) || !is_array($processo['parties'])) {
            continue;
        }

        foreach ($processo['parties'] as $parte) {
            if (!is_array($parte)) {
                continue;
            }

            $docParte = preg_replace('/\D+/', '', (string) (
                $parte['doc']
                ?? $parte['document']
                ?? $parte['documento']
                ?? $parte['cpf']
                ?? $parte['cnpj']
                ?? $parte['cpfCnpj']
                ?? $parte['cpf_cnpj']
                ?? $parte['documentNumber']
                ?? $parte['document_number']
                ?? $parte['taxId']
                ?? $parte['tax_id']
                ?? ''
            ));

            if ($docParte !== $documento) {
                continue;
            }

            foreach (['name', 'nome', 'nomeCompleto', 'razaoSocial', 'razao_social'] as $nomeKey) {
                if (!empty($parte[$nomeKey]) && is_scalar($parte[$nomeKey])) {
                    return trim((string) $parte[$nomeKey]);
                }
            }
        }
    }

    return '';
}

function processos_historico_titulo_processo(array $processo): string
{
    $autor = processos_historico_primeiro_valor($processo, ['author', 'autor']);
    $reu = processos_historico_primeiro_valor($processo, ['reu', 'defendant', 'réu']);
    $titulo = trim($autor . ($autor !== '' && $reu !== '' ? ' x ' : '') . $reu);

    if ($titulo !== '') {
        return $titulo;
    }

    return processos_historico_primeiro_valor($processo, [
        'titulo',
        'title',
        'nome',
        'type',
        'classe',
        'mainSubject',
        'assunto',
    ]);
}

function processos_historico_resumo(array $item): string
{
    $dadosJson = (string) ($item['dados_json'] ?? '');

    if (($item['modalidade_pedido'] ?? '') === 'pdf_processo' && trim((string) ($item['dados_json_relacionado'] ?? '')) !== '') {
        $dadosJson = (string) $item['dados_json_relacionado'];
    }

    $dados = json_decode($dadosJson, true);

    if (!is_array($dados)) {
        return '';
    }

    $modalidade = (string) ($item['modalidade_pedido'] ?? '');
    $entrada = (string) ($item['entrada_original'] ?: $item['entrada_normalizada']);
    $digitos = preg_replace('/\D+/', '', $entrada);
    $processos = processos_historico_lista_processos($dados);

    if ($modalidade === 'consulta_processos' && in_array(strlen($digitos), [11, 14], true)) {
        $nomeDocumento = processos_historico_nome_documento($processos, $digitos);

        if ($nomeDocumento !== '') {
            return $nomeDocumento;
        }

        $nomeTopo = processos_historico_primeiro_valor($dados, [
            'nomeCompleto',
            'nome',
            'razao_social',
            'razaoSocial',
            'searchedDocument.name',
            'searchedDocument.nome',
            'searchedDocument.razaoSocial',
            'searchedDocument.razao_social',
            'data.nomeCompleto',
            'data.nome',
            'data.razaoSocial',
            'data.razao_social',
            'data.searchedDocument.name',
            'data.searchedDocument.nome',
            'data.searchedDocument.razaoSocial',
            'data.searchedDocument.razao_social',
        ]);

        if ($nomeTopo !== '') {
            return $nomeTopo;
        }

        $dadosDocumento = json_decode((string) ($item['dados_json_documento'] ?? ''), true);

        if (is_array($dadosDocumento)) {
            $nomeDocumentoRelacionado = processos_historico_primeiro_valor($dadosDocumento, [
                'result.nomeCompleto',
                'result.nome',
                'result.razao_social',
                'result.razaoSocial',
                'nomeCompleto',
                'nome',
                'razao_social',
                'razaoSocial',
            ]);

            if ($nomeDocumentoRelacionado !== '') {
                return $nomeDocumentoRelacionado;
            }
        }

        if ($processos !== []) {
            return count($processos) . ' processo(s) encontrados.';
        }
    }

    if ($modalidade === 'detalhes_processo' || strlen($digitos) === 20) {
        $processo = processos_historico_primeiro_array($dados, ['data', 'result', 'processo']) ?? $dados;
        $tituloProcesso = processos_historico_titulo_processo($processo);

        if ($tituloProcesso !== '') {
            return $tituloProcesso;
        }
    }

    $candidatos = [
        $dados,
        $dados['result'] ?? null,
        $dados['data'] ?? null,
        $dados['processo'] ?? null,
    ];

    foreach ($candidatos as $candidato) {
        if (!is_array($candidato)) {
            continue;
        }

        foreach (['nomeCompleto', 'nome', 'razao_social', 'razaoSocial', 'titulo', 'title', 'classe', 'mainSubject', 'assunto'] as $key) {
            if (!empty($candidato[$key]) && is_scalar($candidato[$key])) {
                return trim((string) $candidato[$key]);
            }
        }
    }

    if ($processos !== [] && isset($processos[0]) && is_array($processos[0])) {
        $tituloProcesso = processos_historico_titulo_processo($processos[0]);

        if ($tituloProcesso !== '') {
            return $tituloProcesso;
        }
    }

    return '';
}

function processos_consulta_recente_key(array $item): string
{
    $modalidade = (string) ($item['modalidade_pedido'] ?? '');
    $entrada = $item['entrada_original'] ?: $item['entrada_normalizada'];
    $digitos = preg_replace('/\D+/', '', (string) $entrada);

    if ($modalidade === 'dados_cpf' && strlen($digitos) === 11) {
        return 'dados_pessoais:cpf:' . $digitos;
    }

    if ($modalidade === 'dados_cnpj' && strlen($digitos) === 14) {
        return 'dados_pessoais:cnpj:' . $digitos;
    }

    if ($modalidade === 'consulta_processos' && strlen($digitos) === 11) {
        return 'buscar_processos:cpf:' . $digitos;
    }

    if ($modalidade === 'consulta_processos' && strlen($digitos) === 14) {
        return 'buscar_processos:cnpj:' . $digitos;
    }

    if ($modalidade === 'detalhes_processo' && strlen($digitos) === 20) {
        return 'consultar_processo:processo:' . $digitos;
    }

    if ($modalidade === 'pdf_processo' && strlen($digitos) === 20) {
        return 'baixar_integra:processo:' . $digitos;
    }

    return '';
}

function processos_consulta_recente_descricao(array $item): string
{
    $modalidade = (string) ($item['modalidade_pedido'] ?? '');
    $entrada = processos_formatar_entrada($item['entrada_original'] ?: $item['entrada_normalizada'], $modalidade);

    if ($modalidade === 'dados_cpf') {
        return 'dados pessoais pelo CPF ' . $entrada;
    }

    if ($modalidade === 'dados_cnpj') {
        return 'dados pessoais pelo CNPJ ' . $entrada;
    }

    if ($modalidade === 'consulta_processos') {
        $digitos = preg_replace('/\D+/', '', (string) ($item['entrada_original'] ?: $item['entrada_normalizada']));
        return strlen($digitos) === 14
            ? 'processos pelo CNPJ ' . $entrada
            : 'processos pelo CPF ' . $entrada;
    }

    if ($modalidade === 'pdf_processo') {
        return 'o download do processo com CNJ ' . $entrada;
    }

    return 'o processo com CNJ ' . $entrada;
}

function processos_consulta_recente_rotulo(array $item): string
{
    $modalidade = (string) ($item['modalidade_pedido'] ?? '');
    $entrada = $item['entrada_original'] ?: $item['entrada_normalizada'];
    $digitos = preg_replace('/\D+/', '', (string) $entrada);

    if ($modalidade === 'dados_cpf') {
        return 'dados pessoais pelo CPF';
    }

    if ($modalidade === 'dados_cnpj') {
        return 'dados pessoais pelo CNPJ';
    }

    if ($modalidade === 'consulta_processos') {
        return strlen($digitos) === 14 ? 'processos pelo CNPJ' : 'processos pelo CPF';
    }

    if ($modalidade === 'pdf_processo') {
        return 'o download do processo com CNJ';
    }

    return 'o processo com CNJ';
}

function processos_consulta_recente_termo(array $item): string
{
    return processos_formatar_entrada(
        $item['entrada_original'] ?: $item['entrada_normalizada'],
        (string) ($item['modalidade_pedido'] ?? '')
    );
}

function processos_consulta_recente_janela_dias(array $item): int
{
    $data = strtotime((string) ($item['data_pedido'] ?? ''));

    if (!$data) {
        return 30;
    }

    $dias = max(0, (int) floor((time() - $data) / 86400));

    if ($dias <= 7) {
        return 7;
    }

    if ($dias <= 15) {
        return 15;
    }

    return 30;
}

try {
    $mysqli = processos_db();
    $idUsuarioHistorico = processos_usuario_id();
    $pedidosUsuarios = consulta_table('pedidos_usuarios');
    $consultasExternas = consulta_table('consultas_externas');
    $tipoPedidoModel = new TipoPedidoModel($mysqli);
    $custoConsultaModel = new CustoConsultaModel($mysqli);

    foreach ([
        'detalhes_processo' => ['fallback' => 'detalhes_processo', 'var' => 'creditosDetalhesProcesso'],
        'consulta_processos_cpf' => ['fallback' => 'consulta_processos', 'var' => 'creditosBuscarProcessosCpf'],
        'consulta_processos_cnpj' => ['fallback' => 'consulta_processos', 'var' => 'creditosBuscarProcessosCnpj'],
        'dados_cpf' => ['fallback' => 'dados_cpf', 'var' => 'creditosDadosPessoaisCpf'],
        'dados_cnpj' => ['fallback' => 'dados_cnpj', 'var' => 'creditosDadosPessoaisCnpj'],
        'pdf_processo' => ['fallback' => 'pdf_processo', 'var' => 'creditosPdfProcesso'],
    ] as $chaveCusto => $config) {
        $variavelCredito = $config['var'];
        $custoConfig = $custoConsultaModel->buscarPorChave($chaveCusto);

        if ($custoConfig) {
            $$variavelCredito = (float) ($custoConfig['creditos_usuario'] ?? $$variavelCredito);
            continue;
        }

        $tipoPedido = $tipoPedidoModel->buscarPorModalidade($config['fallback']);

        if ($tipoPedido) {
            $$variavelCredito = (float) ($tipoPedido['creditos_usuario'] ?? $$variavelCredito);
        }
    }

    $recentesSql = "SELECT
                p.modalidade_pedido,
                p.entrada_original,
                p.entrada_normalizada,
                p.data_pedido,
                p.id_consulta,
                c.link_pdf_cliente,
                c.link_original_pdf
            FROM {$pedidosUsuarios} p
            LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
            WHERE p.id_usuario = ?
              AND (p.status_resultado = 'sucesso' OR c.status_resultado = 'sucesso')
              AND p.id_consulta IS NOT NULL
              AND p.data_pedido >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY p.data_pedido DESC, p.id_consulta_usuario DESC";
    $recentesStmt = $mysqli->prepare($recentesSql);
    $recentesStmt->bind_param('i', $idUsuarioHistorico);
    $recentesStmt->execute();
    $recentesResult = $recentesStmt->get_result();

    while ($row = $recentesResult->fetch_assoc()) {
        $key = processos_consulta_recente_key($row);

        if ($key === '' || isset($consultasRecentes[$key])) {
            continue;
        }

        $consultasRecentes[$key] = [
            'modalidade' => (string) ($row['modalidade_pedido'] ?? ''),
            'consultaId' => (int) $row['id_consulta'],
            'openUrl' => processos_historico_url($row),
            'description' => processos_consulta_recente_descricao($row),
            'label' => processos_consulta_recente_rotulo($row),
            'term' => processos_consulta_recente_termo($row),
            'windowDays' => processos_consulta_recente_janela_dias($row),
        ];
    }

    $recentesStmt->close();

    $where = "p.id_usuario = ?";
    $types = 'i';
    $params = [$idUsuarioHistorico];

    if ($historicoBusca !== '') {
        $buscaDigitos = preg_replace('/\D+/', '', $historicoBusca) ?? '';
        $where .= " AND (
            p.entrada_original LIKE ?
            OR p.entrada_normalizada LIKE ?
            OR p.mensagem_resultado LIKE ?
            OR c.dados_json LIKE ?
            OR EXISTS (
                SELECT 1
                FROM {$consultasExternas} srchc
                INNER JOIN {$pedidosUsuarios} srchp ON srchp.id_consulta = srchc.id_consulta
                WHERE srchp.id_usuario = p.id_usuario
                  AND srchp.status_resultado = 'sucesso'
                  AND srchc.status_resultado = 'sucesso'
                  AND srchc.entrada_normalizada = p.entrada_normalizada
                  AND srchc.modalidade_pedido IN ('dados_cpf', 'dados_cnpj', 'detalhes_processo')
                  AND srchc.dados_json LIKE ?
                LIMIT 1
            )
        )";
        $like = '%' . $historicoBusca . '%';
        $entradaLike = $buscaDigitos !== '' ? '%' . $buscaDigitos . '%' : $like;
        $types .= 'sssss';
        array_push($params, $like, $entradaLike, $like, $like, $like);
    }

    if ($historicoFiltroDocumento === 'cpf') {
        $where .= " AND CHAR_LENGTH(p.entrada_normalizada) = 11";
    } elseif ($historicoFiltroDocumento === 'cnpj') {
        $where .= " AND CHAR_LENGTH(p.entrada_normalizada) = 14";
    } elseif ($historicoFiltroDocumento === 'cnj') {
        $where .= " AND CHAR_LENGTH(p.entrada_normalizada) = 20";
    }

    if ($historicoFiltroModalidade === 'dados') {
        $where .= " AND p.modalidade_pedido IN ('dados_cpf', 'dados_cnpj')";
    } elseif ($historicoFiltroModalidade !== '') {
        $where .= " AND p.modalidade_pedido = ?";
        $types .= 's';
        $params[] = $historicoFiltroModalidade;
    }

    if ($historicoFiltroStatus === 'expirada') {
        $where .= " AND p.status_resultado = 'sucesso'
                    AND c.data_validade IS NOT NULL
                    AND c.data_validade < NOW()";
    } elseif ($historicoFiltroStatus === 'sucesso') {
        $where .= " AND p.status_resultado = 'sucesso'
                    AND (c.data_validade IS NULL OR c.data_validade >= NOW())";
    } elseif ($historicoFiltroStatus !== '') {
        $where .= " AND p.status_resultado = ?";
        $types .= 's';
        $params[] = $historicoFiltroStatus;
    }

    $countSql = "SELECT COUNT(*) AS total
            FROM {$pedidosUsuarios} p
            LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
            WHERE {$where}";
    $countStmt = $mysqli->prepare($countSql);
    processos_bind_params($countStmt, $types, $params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $historicoTotal = (int) ($countResult->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT
                p.id_consulta_usuario,
                p.modalidade_pedido,
                p.entrada_original,
                p.entrada_normalizada,
                p.data_pedido,
                p.origem,
                p.fornecedor,
                p.status_resultado,
                p.mensagem_resultado,
                p.credito_externo_consumido,
                p.creditos_usuario_consumidos,
                p.id_consulta,
                c.data_validade,
                c.link_pdf_cliente,
                c.link_original_pdf,
                c.dados_json,
                rel.dados_json AS dados_json_relacionado,
                docrel.dados_json AS dados_json_documento
            FROM {$pedidosUsuarios} p
            LEFT JOIN {$consultasExternas} c ON c.id_consulta = p.id_consulta
            LEFT JOIN {$consultasExternas} rel
              ON p.modalidade_pedido = 'pdf_processo'
             AND rel.modalidade_pedido = 'detalhes_processo'
             AND rel.entrada_normalizada = p.entrada_normalizada
             AND rel.status_resultado = 'sucesso'
             AND EXISTS (
                SELECT 1
                FROM {$pedidosUsuarios} relp
                WHERE relp.id_consulta = rel.id_consulta
                  AND relp.id_usuario = p.id_usuario
                  AND relp.status_resultado = 'sucesso'
                LIMIT 1
             )
             AND rel.id_consulta = (
                SELECT rel2.id_consulta
                FROM {$consultasExternas} rel2
                INNER JOIN {$pedidosUsuarios} relp2 ON relp2.id_consulta = rel2.id_consulta
                WHERE rel2.modalidade_pedido = 'detalhes_processo'
                  AND rel2.entrada_normalizada = p.entrada_normalizada
                  AND rel2.status_resultado = 'sucesso'
                  AND relp2.id_usuario = p.id_usuario
                  AND relp2.status_resultado = 'sucesso'
                ORDER BY rel2.data_resposta DESC, rel2.id_consulta DESC
                LIMIT 1
             )
            LEFT JOIN {$consultasExternas} docrel
             ON p.modalidade_pedido = 'consulta_processos'
             AND docrel.modalidade_pedido = CASE
                    WHEN CHAR_LENGTH(p.entrada_normalizada) = 14 THEN 'dados_cnpj'
                    ELSE 'dados_cpf'
                 END
             AND docrel.entrada_normalizada = p.entrada_normalizada
             AND docrel.status_resultado = 'sucesso'
             AND EXISTS (
                SELECT 1
                FROM {$pedidosUsuarios} docp
                WHERE docp.id_consulta = docrel.id_consulta
                  AND docp.id_usuario = p.id_usuario
                  AND docp.status_resultado = 'sucesso'
                LIMIT 1
             )
             AND docrel.id_consulta = (
                SELECT docrel2.id_consulta
                FROM {$consultasExternas} docrel2
                INNER JOIN {$pedidosUsuarios} docp2 ON docp2.id_consulta = docrel2.id_consulta
                WHERE docrel2.modalidade_pedido = CASE
                        WHEN CHAR_LENGTH(p.entrada_normalizada) = 14 THEN 'dados_cnpj'
                        ELSE 'dados_cpf'
                    END
                  AND docrel2.entrada_normalizada = p.entrada_normalizada
                  AND docrel2.status_resultado = 'sucesso'
                  AND docp2.id_usuario = p.id_usuario
                  AND docp2.status_resultado = 'sucesso'
                ORDER BY docrel2.data_resposta DESC, docrel2.id_consulta DESC
                LIMIT 1
             )
            WHERE {$where}
            ORDER BY p.data_pedido DESC
            LIMIT ? OFFSET ?";

    $stmt = $mysqli->prepare($sql);
    $selectTypes = $types . 'ii';
    $selectParams = array_merge($params, [$historicoLimite, $historicoOffset]);
    processos_bind_params($stmt, $selectTypes, $selectParams);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $historicoConsultas[] = $row;
    }

    $stmt->close();

    if ($historicoFiltroStatus !== '') {
        $historicoConsultas = array_values(array_filter(
            $historicoConsultas,
            static function (array $item) use ($historicoFiltroStatus): bool {
                return processos_historico_status($item) === $historicoFiltroStatus;
            }
        ));
    }

    $mysqli->close();
} catch (Throwable $exception) {
    $historicoErro = 'Não foi possível carregar o histórico agora.';
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
    <title>Consulta de Processos</title>
</head>

<body>
    <div id="main">
        <?php include_once 'header/header.php'; ?>

        <main class="processos-page">
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
                        <strong><?= htmlspecialchars(processos_creditos_formatar($creditos), ENT_QUOTES, 'UTF-8'); ?></strong>
                        Créditos
                        <span class="credit-add">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        </span>
                    </button>
                </div>
            </section>

            <?php if ($creditosErro !== null && bidmap_local_login_enabled()): ?>
                <div class="processos-dev-alert" role="alert">
                    <strong>Erro ao buscar créditos:</strong>
                    <?= htmlspecialchars($creditosErro, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <section class="processos-hero">
                <div class="hero-icon bidmap-hero-logo" aria-hidden="true">
                    <img src="img/bidmap_logo_sem_borda.png" alt="">
                </div>

                <h1>Consulte processos<br>e dados cadastrais</h1>
                <p>Pesquise por número do processo, CPF ou CNPJ.</p>

                <form class="consulta-form" action="#" method="get">
                    <input type="hidden" name="processos_action_token" value="<?= htmlspecialchars($actionToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="return" value="consultar_processos.php">
                    <fieldset class="search-type" aria-label="Tipo de consulta">
                        <label>
                            <input type="radio" name="tipo_consulta" value="processo">
                            <span>CNJ</span>
                        </label>
                        <label>
                            <input type="radio" name="tipo_consulta" value="cpf" checked>
                            <span>CPF</span>
                        </label>
                        <label>
                            <input type="radio" name="tipo_consulta" value="cnpj">
                            <span>CNPJ</span>
                        </label>
                    </fieldset>

                    <div class="search-row">
                        <label class="sr-only" for="termo-consulta">Termo da consulta</label>
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input id="termo-consulta" name="q" type="text" placeholder="Digite o CPF">
                    </div>

                    <div class="security-note">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        Seus dados ficam protegidos e a consulta só usa créditos após confirmação.
                    </div>

                    <div class="form-actions">
                        <button class="consultar-btn action-processo js-consulta-confirm"
                            type="submit"
                            name="acao"
                            value="consultar_processo"
                            formaction="detalhe_processo.php"
                            formmethod="post"
                            data-title-prefix="Ver detalhes do processo:"
                            data-credit-label="<?= htmlspecialchars(processos_creditos_formatar($creditosDetalhesProcesso), ENT_QUOTES, 'UTF-8'); ?>">
                            Ver detalhes do processo
                        </button>
                        <button class="consultar-btn secondary action-processo action-processo-pdf js-consulta-confirm"
                            type="submit"
                            name="acao"
                            value="baixar_integra"
                            formaction="api/processos/documentos/pdf.php"
                            formmethod="post"
                            data-title-prefix="Baixar processo na &iacute;ntegra:"
                            data-credit-label="<?= htmlspecialchars(processos_creditos_formatar($creditosPdfProcesso), ENT_QUOTES, 'UTF-8'); ?>"
                            data-pdf-action="1">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            Baixar processo na &iacute;ntegra
                        </button>
                        <button class="consultar-btn action-documento js-consulta-confirm"
                            type="submit"
                            name="acao"
                            value="buscar_processos"
                            formaction="api/processos/fila/adicionar_a_fila.php"
                            formmethod="post"
                            data-credit-cpf="<?= htmlspecialchars(processos_creditos_formatar($creditosBuscarProcessosCpf), ENT_QUOTES, 'UTF-8'); ?>"
                            data-credit-cnpj="<?= htmlspecialchars(processos_creditos_formatar($creditosBuscarProcessosCnpj), ENT_QUOTES, 'UTF-8'); ?>"
                            hidden>
                            Buscar processos
                        </button>
                        <button class="consultar-btn secondary action-documento js-consulta-confirm"
                            type="submit"
                            name="acao"
                            value="dados_pessoais"
                            formaction="resultado_dados_pessoais.php"
                            formmethod="get"
                            data-title-prefix="Ver dados pessoais:"
                            data-credit-cpf="<?= htmlspecialchars(processos_creditos_formatar($creditosDadosPessoaisCpf), ENT_QUOTES, 'UTF-8'); ?>"
                            data-credit-cnpj="<?= htmlspecialchars(processos_creditos_formatar($creditosDadosPessoaisCnpj), ENT_QUOTES, 'UTF-8'); ?>"
                            hidden>
                            Ver dados pessoais
                        </button>
                    </div>
                </form>
            </section>

            <section class="processos-panel" id="historico-consultas" aria-label="Últimas consultas">
                <div class="panel-header">
                    <div>
                        <h2>Últimas consultas</h2>
                        <p>Seus últimos pedidos</p>
                    </div>
                    <a class="ghost-btn" href="<?= $historicoCompleto ? 'consultar_processos.php#historico-consultas' : 'consultar_processos.php?historico=1#historico-consultas'; ?>">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                        <?= $historicoCompleto ? 'Ver recentes' : 'Ver todas'; ?>
                    </a>
                </div>

                <?php if ($historicoCompleto): ?>
                    <form class="history-search" method="get" action="consultar_processos.php#historico-consultas">
                        <input type="hidden" name="historico" value="1">
                        <label class="sr-only" for="historico-busca">Pesquisar no historico</label>
                        <div class="history-search-main">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="historico-busca" type="search" name="busca" value="<?= htmlspecialchars($historicoBusca, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Pesquisar por numero, nome ou status">
                        </div>
                        <label class="sr-only" for="historico-documento">Tipo de documento</label>
                        <select id="historico-documento" name="documento" aria-label="Filtrar por tipo de documento">
                            <option value=""<?= $historicoFiltroDocumento === '' ? ' selected' : ''; ?>>Todos documentos</option>
                            <option value="cpf"<?= $historicoFiltroDocumento === 'cpf' ? ' selected' : ''; ?>>CPF</option>
                            <option value="cnpj"<?= $historicoFiltroDocumento === 'cnpj' ? ' selected' : ''; ?>>CNPJ</option>
                            <option value="cnj"<?= $historicoFiltroDocumento === 'cnj' ? ' selected' : ''; ?>>CNJ</option>
                        </select>
                        <label class="sr-only" for="historico-modalidade">Tipo de consulta</label>
                        <select id="historico-modalidade" name="modalidade" aria-label="Filtrar por tipo de consulta">
                            <option value=""<?= $historicoFiltroModalidade === '' ? ' selected' : ''; ?>>Todas consultas</option>
                            <option value="consulta_processos"<?= $historicoFiltroModalidade === 'consulta_processos' ? ' selected' : ''; ?>>Processos</option>
                            <option value="dados"<?= $historicoFiltroModalidade === 'dados' ? ' selected' : ''; ?>>Dados cadastrais</option>
                            <option value="detalhes_processo"<?= $historicoFiltroModalidade === 'detalhes_processo' ? ' selected' : ''; ?>>Detalhes</option>
                            <option value="pdf_processo"<?= $historicoFiltroModalidade === 'pdf_processo' ? ' selected' : ''; ?>>PDF</option>
                        </select>
                        <label class="sr-only" for="historico-status">Status</label>
                        <select id="historico-status" name="status" aria-label="Filtrar por status">
                            <option value=""<?= $historicoFiltroStatus === '' ? ' selected' : ''; ?>>Todos status</option>
                            <option value="sucesso"<?= $historicoFiltroStatus === 'sucesso' ? ' selected' : ''; ?>>Disponivel</option>
                            <option value="pendente"<?= $historicoFiltroStatus === 'pendente' ? ' selected' : ''; ?>>Pendente</option>
                            <option value="erro"<?= $historicoFiltroStatus === 'erro' ? ' selected' : ''; ?>>Erro</option>
                            <option value="estornado"<?= $historicoFiltroStatus === 'estornado' ? ' selected' : ''; ?>>Estornado</option>
                            <option value="saldo_insuficiente"<?= $historicoFiltroStatus === 'saldo_insuficiente' ? ' selected' : ''; ?>>Saldo insuficiente</option>
                            <option value="expirada"<?= $historicoFiltroStatus === 'expirada' ? ' selected' : ''; ?>>Expirada</option>
                        </select>
                        <button type="submit">Pesquisar</button>
                        <?php if ($historicoBusca !== '' || $historicoFiltroDocumento !== '' || $historicoFiltroModalidade !== '' || $historicoFiltroStatus !== ''): ?>
                            <a href="consultar_processos.php?historico=1#historico-consultas">Limpar</a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($historicoErro): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($historicoErro, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php elseif (empty($historicoConsultas)): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open" aria-hidden="true"></i>
                        <span>
                            <?= $historicoCompleto && ($historicoBusca !== '' || $historicoFiltroDocumento !== '' || $historicoFiltroModalidade !== '' || $historicoFiltroStatus !== '')
                                ? 'Nenhuma entrada encontrada com esses parâmetros.'
                                : 'Nenhuma consulta realizada por este usuário ainda.'; ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="history-list">
                        <?php foreach ($historicoConsultas as $item): ?>
                            <?php
                            $status = processos_historico_status($item);
                            $statusClass = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($status));
                            $dataPedido = $item['data_pedido'] ? date('d/m/Y H:i', strtotime($item['data_pedido'])) : '';
                            $entradaHistorico = processos_formatar_entrada(
                                $item['entrada_original'] ?: $item['entrada_normalizada'],
                                $item['modalidade_pedido'] ?? ''
                            );
                            $isPdfProcesso = ($item['modalidade_pedido'] ?? '') === 'pdf_processo';
                            $historicoUrl = processos_historico_url($item);
                            $historicoSemAcesso = ($isPdfProcesso && $status !== 'sucesso')
                                || in_array($status, ['pendente', 'estornado', 'expirado', 'expirada', 'saldo_insuficiente'], true)
                                || (!$isPdfProcesso && $historicoUrl === '');
                            $pdfPodeAcompanhar = $isPdfProcesso && $status === 'sucesso';
                            $podeAcompanharPedido = $status === 'pendente';
                            $historyTrackingAttrs = $podeAcompanharPedido
                                ? ' data-history-item data-pedido-id="' . htmlspecialchars((string) $item['id_consulta_usuario'], ENT_QUOTES, 'UTF-8') . '" data-consulta-id="' . htmlspecialchars((string) ($item['id_consulta'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-modalidade="' . htmlspecialchars((string) ($item['modalidade_pedido'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-action-token="' . htmlspecialchars($actionToken, ENT_QUOTES, 'UTF-8') . '"'
                                : '';
                            ?>
                            <article class="history-item"<?= $historyTrackingAttrs; ?><?= $pdfPodeAcompanhar ? ' data-pdf-history-item' : ''; ?>>
                                <div class="history-main">
                                    <span class="history-type"><?= htmlspecialchars(processos_historico_tipo_label($item), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?= htmlspecialchars($entradaHistorico, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php $resumoHistorico = processos_historico_resumo($item); ?>
                                    <?php if ($resumoHistorico !== ''): ?>
                                        <span class="history-summary"><?= htmlspecialchars($resumoHistorico, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <small>
                                        <?= htmlspecialchars($dataPedido, ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                </div>
                                <div class="history-meta">
                                    <span class="status-pill status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"<?= $podeAcompanharPedido ? ' data-history-status' : ''; ?><?= $isPdfProcesso ? ' data-pdf-status' : ''; ?>>
                                        <?= htmlspecialchars(processos_status_label($status), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if ($isPdfProcesso && $pdfPodeAcompanhar): ?>
                                        <form class="history-download-form" method="post" action="api/processos/documentos/pdf.php">
                                            <input type="hidden" name="consulta_id" value="<?= htmlspecialchars((string) ($item['id_consulta'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="numero" value="<?= htmlspecialchars($item['entrada_original'] ?: $item['entrada_normalizada'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="return" value="<?= htmlspecialchars(processos_historico_return_url($historicoCompleto, $historicoPagina, $historicoBusca, $historicoFiltroDocumento, $historicoFiltroModalidade, $historicoFiltroStatus), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="origem" value="historico">
                                            <input type="hidden" name="processos_action_token" value="<?= htmlspecialchars($actionToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" data-history-action data-pdf-history-submit class="<?= $status === 'sucesso' ? 'download-ready' : 'history-action-disabled'; ?>"<?= $status !== 'sucesso' ? ' disabled aria-disabled="true"' : ''; ?>>
                                                Baixar
                                            </button>
                                        </form>
                                    <?php elseif ($historicoSemAcesso): ?>
                                        <button type="button" class="history-action-disabled" data-history-action disabled aria-disabled="true">
                                            <?= $isPdfProcesso ? 'Baixar' : 'Abrir'; ?>
                                        </button>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($historicoUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                            Abrir
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    $totalPaginasHistorico = max(1, (int) ceil($historicoTotal / $historicoLimite));
                    $historicoInicioPagina = $historicoTotal > 0 ? $historicoOffset + 1 : 0;
                    $historicoFimPagina = min($historicoOffset + count($historicoConsultas), $historicoTotal);
                    ?>
                    <?php if ($historicoCompleto && $historicoTotal > 0): ?>
                        <nav class="process-pagination" aria-label="Paginas do historico">
                            <?php
                            $baseHistoricoParams = ['historico' => 1];
                            if ($historicoBusca !== '') {
                                $baseHistoricoParams['busca'] = $historicoBusca;
                            }
                            if ($historicoFiltroDocumento !== '') {
                                $baseHistoricoParams['documento'] = $historicoFiltroDocumento;
                            }
                            if ($historicoFiltroModalidade !== '') {
                                $baseHistoricoParams['modalidade'] = $historicoFiltroModalidade;
                            }
                            if ($historicoFiltroStatus !== '') {
                                $baseHistoricoParams['status'] = $historicoFiltroStatus;
                            }
                            ?>
                            <span class="process-pagination-summary">
                                Mostrando <?= htmlspecialchars((string) $historicoInicioPagina, ENT_QUOTES, 'UTF-8'); ?>-<?= htmlspecialchars((string) $historicoFimPagina, ENT_QUOTES, 'UTF-8'); ?>
                                de <?= htmlspecialchars((string) $historicoTotal, ENT_QUOTES, 'UTF-8'); ?> consultas
                            </span>
                            <a class="<?= $historicoPagina <= 1 ? 'disabled' : ''; ?>" href="consultar_processos.php?<?= htmlspecialchars(http_build_query($baseHistoricoParams + ['page' => max(1, $historicoPagina - 1)]), ENT_QUOTES, 'UTF-8'); ?>#historico-consultas">Anterior</a>
                            <?php for ($i = max(1, $historicoPagina - 2); $i <= min($totalPaginasHistorico, $historicoPagina + 2); $i++): ?>
                                <a class="<?= $i === $historicoPagina ? 'current' : ''; ?>" href="consultar_processos.php?<?= htmlspecialchars(http_build_query($baseHistoricoParams + ['page' => $i]), ENT_QUOTES, 'UTF-8'); ?>#historico-consultas"><?= htmlspecialchars((string) $i, ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php endfor; ?>
                            <span>Página <?= htmlspecialchars((string) $historicoPagina, ENT_QUOTES, 'UTF-8'); ?> de <?= htmlspecialchars((string) $totalPaginasHistorico, ENT_QUOTES, 'UTF-8'); ?></span>
                            <a class="<?= $historicoPagina >= $totalPaginasHistorico ? 'disabled' : ''; ?>" href="consultar_processos.php?<?= htmlspecialchars(http_build_query($baseHistoricoParams + ['page' => min($totalPaginasHistorico, $historicoPagina + 1)]), ENT_QUOTES, 'UTF-8'); ?>#historico-consultas">Próxima</a>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

        </main>

        <div class="credit-modal" data-credit-modal hidden>
            <div class="credit-modal__backdrop" data-credit-modal-close></div>
            <section class="credit-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="credit-modal-title">
                <button class="credit-modal__close" type="button" aria-label="Fechar" data-credit-modal-close>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>

                <div class="credit-modal__icon" aria-hidden="true">
                    <i class="fa-solid fa-coins"></i>
                </div>

                <h2 id="credit-modal-title">Adicionar créditos</h2>
                <div class="credit-current-balance">
                    Saldo atual
                    <strong><?= htmlspecialchars(processos_creditos_formatar($creditos), ENT_QUOTES, 'UTF-8'); ?> créditos</strong>
                </div>
                <p>Escolha um pacote para abrir o checkout. Quando o pagamento for confirmado, o saldo será atualizado na sua conta.</p>

                <a class="credit-extract-link" href="historico_consultas.php">
                    Ver extrato de créditos
                </a>

                <div class="credit-packages">
                    <?php foreach ($checkoutCreditos as $valor => $url): ?>
                        <a class="credit-package" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                            <span>Adicionar</span>
                            <strong>R$ <?= htmlspecialchars(number_format((float) $valor, 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="paid-confirm-modal" id="consultaConfirmModal" hidden>
            <div class="paid-confirm-backdrop" data-consulta-modal-close></div>
            <section class="paid-confirm-card" role="dialog" aria-modal="true" aria-labelledby="consultaConfirmTitle">
                <button class="paid-confirm-close" type="button" data-consulta-modal-close aria-label="Fechar">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
                <div class="paid-confirm-header">
                    <div class="paid-confirm-icon" aria-hidden="true">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <h2 id="consultaConfirmTitle">Confirmar consulta</h2>
                </div>
                <p id="consultaConfirmMessage">Serão gastos créditos para executar esta ação.</p>
                <div class="paid-confirm-actions">
                    <button type="button" data-consulta-modal-close>Cancelar</button>
                    <a href="#" id="consultaOpenSaved" hidden>Abrir consulta salva</a>
                    <a href="#" id="consultaConfirmSubmit">Confirmar</a>
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
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const typeInputs = document.querySelectorAll('input[name="tipo_consulta"]');
            const searchInput = document.getElementById('termo-consulta');
            const processoActions = document.querySelectorAll('.action-processo');
            const documentoActions = document.querySelectorAll('.action-documento');
            const creditModal = document.querySelector('[data-credit-modal]');
            const creditModalOpen = document.querySelector('[data-credit-modal-open]');
            const creditModalClose = document.querySelectorAll('[data-credit-modal-close]');
            const loadingOverlay = document.getElementById('processosLoadingOverlay');
            const loadingTitle = document.getElementById('processosLoadingTitle');
            const loadingMessage = document.getElementById('processosLoadingMessage');
            const consultaModal = document.getElementById('consultaConfirmModal');
            const consultaTitle = document.getElementById('consultaConfirmTitle');
            const consultaMessage = document.getElementById('consultaConfirmMessage');
            const consultaSubmit = document.getElementById('consultaConfirmSubmit');
            const consultaOpenSaved = document.getElementById('consultaOpenSaved');
            const consultaModalClose = document.querySelectorAll('[data-consulta-modal-close]');
            const consultaCancelAction = consultaModal ? consultaModal.querySelector('.paid-confirm-actions [data-consulta-modal-close]') : null;
            const creditosDisponiveis = <?= json_encode((float) $creditos); ?>;
            const erroInicial = <?= json_encode($consultaErro !== '' ? $consultaErro : null, JSON_UNESCAPED_UNICODE); ?>;
            const pdfProcessandoMensagem = <?= json_encode($pdfProcessandoMensagem !== '' ? $pdfProcessandoMensagem : null, JSON_UNESCAPED_UNICODE); ?>;
            const consultasRecentes = <?= json_encode($consultasRecentes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let pendingForm = null;
            let pendingSubmitter = null;
            let pendingConfirmed = false;
            let consultaForceUpdateOnSubmit = false;
            let consultaModalLocked = false;

            function showProcessosLoading(title, message) {
                if (!loadingOverlay) {
                    return;
                }

                if (loadingTitle && title) {
                    loadingTitle.textContent = title;
                }

                if (loadingMessage) {
                    loadingMessage.textContent = message || '';
                    loadingMessage.hidden = !message;
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

            function normalizeDigits(value) {
                return String(value || '').replace(/\D+/g, '');
            }

            function detectConsultaType(value) {
                const raw = String(value || '').trim();
                const digits = normalizeDigits(raw);

                if (digits.length === 11) {
                    return 'cpf';
                }

                if (digits.length === 14) {
                    return 'cnpj';
                }

                if (digits.length === 20 || /^\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}$/.test(raw)) {
                    return 'processo';
                }

                return '';
            }

            function selectConsultaType(type) {
                if (!type) {
                    return;
                }

                const input = document.querySelector('input[name="tipo_consulta"][value="' + type + '"]');

                if (input && !input.checked) {
                    input.checked = true;
                    updateActions();
                }
            }

            function formatCredits(value) {
                const numeric = Number(value || 0);
                return numeric.toFixed(2);
            }

            function openConsultaModal(titleText, messageText, options) {
                if (!consultaModal || !consultaTitle || !consultaMessage || !consultaSubmit) {
                    return;
                }

                const config = options || {};
                hideProcessosLoading();
                consultaModalLocked = !!config.locked;
                consultaModal.classList.toggle('is-locked', consultaModalLocked);
                consultaForceUpdateOnSubmit = !!config.forceUpdateOnSubmit;
                consultaTitle.textContent = titleText || 'Confirmar consulta?';
                if (config.messageHtml) {
                    consultaMessage.innerHTML = config.messageHtml;
                } else {
                    consultaMessage.textContent = messageText || '';
                }
                consultaSubmit.hidden = !!config.infoOnly;
                consultaSubmit.textContent = config.submitText || 'Confirmar';
                if (consultaCancelAction) {
                    consultaCancelAction.textContent = config.cancelText || (config.infoOnly ? 'Entendi' : 'Cancelar');
                    consultaCancelAction.hidden = !!config.hideCancel;
                }
                if (consultaOpenSaved) {
                    consultaOpenSaved.hidden = !config.openSavedUrl;
                    consultaOpenSaved.href = config.openSavedUrl || '#';
                    consultaOpenSaved.textContent = config.openSavedText || 'Abrir consulta salva';
                    if (config.openSavedPrimary) {
                        consultaOpenSaved.classList.add('is-primary-action');
                    } else {
                        consultaOpenSaved.classList.remove('is-primary-action');
                    }
                }
                consultaModal.hidden = false;
                document.body.classList.add('modal-open');
            }

            function limparPdfStatusDaUrl() {
                if (!window.history || !window.history.replaceState) {
                    return;
                }

                const url = new URL(window.location.href);
                if (!url.searchParams.has('pdf_status') && !url.searchParams.has('pdf_message')) {
                    return;
                }

                url.searchParams.delete('pdf_status');
                url.searchParams.delete('pdf_message');
                window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function closeConsultaModal() {
                if (!consultaModal) {
                    return;
                }

                if (consultaModalLocked) {
                    return;
                }

                consultaModal.hidden = true;
                consultaModal.classList.remove('is-locked');
                document.body.classList.remove('modal-open');
                pendingForm = null;
                pendingSubmitter = null;
                consultaForceUpdateOnSubmit = false;
                consultaModalLocked = false;
                if (consultaCancelAction) {
                    consultaCancelAction.hidden = false;
                }
                if (consultaOpenSaved) {
                    consultaOpenSaved.classList.remove('is-primary-action');
                }
                setAtualizarFlag(null, false);
            }

            function actionCost(submitter, selectedType) {
                if (!submitter) {
                    return 0;
                }

                if (selectedType === 'cpf' && submitter.hasAttribute('data-credit-cpf')) {
                    return Number(submitter.getAttribute('data-credit-cpf')) || 0;
                }

                if (selectedType === 'cnpj' && submitter.hasAttribute('data-credit-cnpj')) {
                    return Number(submitter.getAttribute('data-credit-cnpj')) || 0;
                }

                return Number(submitter.getAttribute('data-credit-label')) || 0;
            }

            function consultaRecenteKey(selectedType, action, value) {
                const digits = normalizeDigits(value);

                if ((action === 'buscar_processos' || action === 'dados_pessoais') && (selectedType === 'cpf' || selectedType === 'cnpj')) {
                    return action + ':' + selectedType + ':' + digits;
                }

                if ((action === 'consultar_processo' || action === 'baixar_integra') && digits.length === 20) {
                    return action + ':processo:' + digits;
                }

                return '';
            }

            function consultaRecente(selectedType, action, value) {
                const key = consultaRecenteKey(selectedType, action, value);
                return key ? (consultasRecentes[key] || null) : null;
            }

            function setAtualizarFlag(form, enabled) {
                if (!form) {
                    document.querySelectorAll('input[name="atualizar"][data-dynamic-update]').forEach(function (input) {
                        input.remove();
                    });
                    return;
                }

                let input = form.querySelector('input[name="atualizar"][data-dynamic-update]');

                if (!enabled) {
                    if (input) {
                        input.remove();
                    }
                    return;
                }

                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'atualizar';
                    input.setAttribute('data-dynamic-update', '1');
                    form.appendChild(input);
                }

                input.value = '1';
            }

            function validateAction(selectedType, action, value) {
                const digits = normalizeDigits(value);

                if (action === 'consultar_processo' || action === 'baixar_integra') {
                    return digits.length === 20 ? '' : 'Número CNJ inválido.';
                }

                if (selectedType === 'cpf') {
                    return digits.length === 11 ? '' : 'CPF inválido.';
                }

                if (selectedType === 'cnpj') {
                    return digits.length === 14 ? '' : 'CNPJ inválido.';
                }

                return value.trim() !== '' ? '' : 'Informe o termo da consulta.';
            }

            function confirmActionText(selectedType, action, value, submitter) {
                const prefix = submitter ? (submitter.getAttribute('data-title-prefix') || '') : '';
                const term = String(value || '').trim();

                if (action === 'buscar_processos') {
                    const titlePrefix = selectedType === 'cnpj'
                        ? 'Buscar processos pelo CNPJ:'
                        : 'Buscar processos pelo CPF:';

                    return term !== '' ? titlePrefix + ' ' + term : titlePrefix;
                }

                return term !== '' && prefix ? prefix + ' ' + term : (prefix || 'Confirmar consulta:');
            }

            function confirmMessageHtml(actionText, cost) {
                return escapeHtml(actionText) + '. Serão gastos <strong>' + escapeHtml(formatCredits(cost) + ' créditos') + '</strong>.';
            }

            function consultaRecenteMessageHtml(recente, cost) {
                const label = recente.label || recente.description || 'esta consulta';
                const term = recente.term || '';
                const isPdf = recente.modalidade === 'pdf_processo';
                const acaoSalva = isPdf ? 'baixar a versão já salva' : 'abrir a consulta já realizada';
                const acaoAtualizar = isPdf ? 'baixar uma versão atualizada' : 'atualizar para buscar dados mais recentes';

                return 'Você já buscou ' +
                    escapeHtml(isPdf ? 'o PDF do processo com CNJ' : label) +
                    (term ? ' <strong>' + escapeHtml(term) + '</strong>' : '') +
                    ' nos últimos ' +
                    escapeHtml(String(recente.windowDays || 30)) +
                    ' dias. Deseja ' +
                    escapeHtml(acaoSalva) +
                    ' sem gastar créditos ou ' +
                    escapeHtml(acaoAtualizar) +
                    ' por <strong>' +
                    escapeHtml(formatCredits(cost) + ' créditos') +
                    '</strong>?';
            }

            function creditosInsuficientesMessageHtml(cost) {
                return 'Esta a&ccedil;&atilde;o exige ' +
                    escapeHtml(formatCredits(cost)) +
                    ' cr&eacute;ditos. Seu saldo atual &eacute; de ' +
                    escapeHtml(formatCredits(creditosDisponiveis)) +
                    ' cr&eacute;ditos.<a href="#" data-insufficient-add-credits>Adicionar cr&eacute;ditos</a>';
            }

            function updateActions() {
                const selectedType = document.querySelector('input[name="tipo_consulta"]:checked').value;
                const isProcesso = selectedType === 'processo';

                processoActions.forEach(function (button) {
                    button.hidden = !isProcesso;
                });
                documentoActions.forEach(function (button) {
                    button.hidden = isProcesso;
                });

                if (selectedType === 'cpf') {
                    searchInput.placeholder = 'Digite o CPF';
                } else if (selectedType === 'cnpj') {
                    searchInput.placeholder = 'Digite o CNPJ';
                } else {
                    searchInput.placeholder = 'Digite o número do processo';
                }
            }

            typeInputs.forEach(function (input) {
                input.addEventListener('change', updateActions);
            });

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    selectConsultaType(detectConsultaType(searchInput.value));
                });

                searchInput.addEventListener('blur', function () {
                    selectConsultaType(detectConsultaType(searchInput.value));
                });
            }

            document.querySelectorAll('.consulta-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    const submitter = event.submitter;
                    const action = submitter ? submitter.value : '';
                    const selectedType = document.querySelector('input[name="tipo_consulta"]:checked').value;
                    const value = searchInput ? searchInput.value : '';
                    const cost = actionCost(submitter, selectedType);

                    if (!pendingConfirmed) {
                        const validationMessage = validateAction(selectedType, action, value);

                        event.preventDefault();

                        if (validationMessage) {
                            openConsultaModal('Não foi possível concluir a consulta', validationMessage, {
                                infoOnly: true
                            });
                            return;
                        }

                        if (cost > 0 && creditosDisponiveis < cost && !consultaRecente(selectedType, action, value)) {
                            openConsultaModal(
                                'Créditos insuficientes',
                                '',
                                {
                                    infoOnly: true,
                                    messageHtml: creditosInsuficientesMessageHtml(cost)
                                }
                            );
                            return;
                        }

                        pendingForm = form;
                        pendingSubmitter = submitter;

                        const recente = consultaRecente(selectedType, action, value);

                        if (recente) {
                            const isPdfRecente = recente.modalidade === 'pdf_processo';
                            setAtualizarFlag(form, false);
                            openConsultaModal(
                                'Consulta já realizada',
                                '',
                                {
                                    submitText: isPdfRecente ? 'Baixar versão atualizada' : 'Atualizar consulta',
                                    forceUpdateOnSubmit: true,
                                    openSavedUrl: recente.openUrl,
                                    openSavedText: isPdfRecente ? 'Baixar sem gastar créditos' : 'Abrir sem gastar créditos',
                                    messageHtml: consultaRecenteMessageHtml(recente, cost)
                                }
                            );
                            return;
                        }

                        setAtualizarFlag(form, false);
                        openConsultaModal(
                            'Confirmar consulta',
                            '',
                            {
                                submitText: 'Confirmar',
                                messageHtml: confirmMessageHtml(confirmActionText(selectedType, action, value, submitter), cost)
                            }
                        );
                        return;
                    }

                    if (action === 'baixar_integra') {
                        return;
                    }

                    if (action === 'buscar_processos') {
                        showProcessosLoading('Buscando processos', '');
                        return;
                    }

                    if (action === 'dados_pessoais') {
                        showProcessosLoading('Buscando dados', '');
                        return;
                    }

                    showProcessosLoading('Consultando dados', '');
                });
            });

            if (consultaSubmit) {
                consultaSubmit.addEventListener('click', function (event) {
                    event.preventDefault();

                    if (!pendingForm || !pendingSubmitter) {
                        return;
                    }

                    const action = pendingSubmitter.value;
                    const formToSubmit = pendingForm;
                    const submitterToUse = pendingSubmitter;
                    const deveAtualizarConsulta = consultaForceUpdateOnSubmit;
                    const selectedType = document.querySelector('input[name="tipo_consulta"]:checked').value;
                    const cost = actionCost(submitterToUse, selectedType);

                    if (deveAtualizarConsulta && cost > 0 && creditosDisponiveis < cost) {
                        setAtualizarFlag(formToSubmit, false);
                        openConsultaModal(
                            'Créditos insuficientes',
                            '',
                            {
                                infoOnly: true,
                                messageHtml: creditosInsuficientesMessageHtml(cost)
                            }
                        );
                        return;
                    }

                    if (action === 'baixar_integra') {
                        pendingConfirmed = true;
                        closeConsultaModal();
                        setAtualizarFlag(formToSubmit, deveAtualizarConsulta);
                        formToSubmit.requestSubmit(submitterToUse);
                        return;
                    }

                    closeConsultaModal();
                    setAtualizarFlag(formToSubmit, deveAtualizarConsulta);
                    pendingConfirmed = true;
                    formToSubmit.requestSubmit(submitterToUse);
                });
            }

            consultaModalClose.forEach(function (button) {
                button.addEventListener('click', closeConsultaModal);
            });

            if (consultaModal) {
                consultaModal.addEventListener('click', function (event) {
                    const link = event.target.closest('[data-insufficient-add-credits]');

                    if (!link) {
                        return;
                    }

                    event.preventDefault();
                    closeConsultaModal();

                    if (typeof window.bidmapOpenCreditModal === 'function') {
                        window.bidmapOpenCreditModal();
                    } else if (creditModal) {
                        creditModal.hidden = false;
                        document.body.classList.add('modal-open');
                    }
                });
            }

            function bindHistoryDownloadForms(scope) {
                (scope || document).querySelectorAll('.history-download-form').forEach(function (form) {
                    if (form.getAttribute('data-history-download-bound') === '1') {
                        return;
                    }

                    form.setAttribute('data-history-download-bound', '1');
                    form.addEventListener('submit', function () {
                        const returnInput = form.querySelector('input[name="return"]');

                        if (returnInput) {
                            const returnUrl = new URL(window.location.href);
                            returnUrl.hash = 'historico-consultas';
                            returnUrl.searchParams.delete('pdf_status');
                            returnUrl.searchParams.delete('pdf_message');
                            returnUrl.searchParams.delete('consulta_erro');
                            returnInput.value = returnUrl.pathname + returnUrl.search + returnUrl.hash;
                        }

                        showProcessosLoading(
                            'Baixando na íntegra',
                            'Estamos verificando se o PDF já está pronto. Se estiver disponível, o download começará automaticamente.'
                        );
                    });
                });
            }

            bindHistoryDownloadForms(document);

            const historicoPanel = document.getElementById('historico-consultas');
            let historicoAjaxController = null;
            let historicoBuscaTimer = null;

            function historicoUrlNormalizada(url) {
                const target = new URL(url, window.location.href);
                target.hash = 'historico-consultas';

                return target;
            }

            function historicoAjaxAplicavel(url) {
                return historicoPanel && url.origin === window.location.origin && /consultar_processos\.php$/.test(url.pathname);
            }

            function atualizarHistoricoPorAjax(url, options) {
                const target = historicoUrlNormalizada(url);

                if (!historicoAjaxAplicavel(target)) {
                    return Promise.resolve(false);
                }

                if (historicoAjaxController) {
                    historicoAjaxController.abort();
                }

                const controller = new AbortController();
                historicoAjaxController = controller;
                historicoPanel.classList.add('history-loading');

                return fetch(target.href, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    signal: controller.signal
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Falha ao carregar historico.');
                        }

                        return response.text();
                    })
                    .then(function (html) {
                        const parsed = new DOMParser().parseFromString(html, 'text/html');
                        const nextPanel = parsed.getElementById('historico-consultas');

                        if (!nextPanel) {
                            throw new Error('Historico nao encontrado na resposta.');
                        }

                        historicoPanel.innerHTML = nextPanel.innerHTML;
                        bindHistoryDownloadForms(historicoPanel);

                        if (options && options.replaceState === true) {
                            window.history.replaceState({ historicoAjax: true }, '', target.pathname + target.search + target.hash);
                        } else {
                            window.history.pushState({ historicoAjax: true }, '', target.pathname + target.search + target.hash);
                        }

                        pedidoPollingStep = 0;
                        pedidoPollingChecks = 0;
                        if (pedidoPollingTimer) {
                            window.clearTimeout(pedidoPollingTimer);
                            pedidoPollingTimer = null;
                        }
                        agendarPollingPedidosPendentes();

                        return true;
                    })
                    .catch(function (error) {
                        if (error && error.name === 'AbortError') {
                            return true;
                        }

                        return false;
                    })
                    .finally(function () {
                        if (historicoAjaxController === controller && !controller.signal.aborted) {
                            historicoPanel.classList.remove('history-loading');
                        }
                    });
            }

            function historicoUrlDoFormulario(form) {
                const data = new FormData(form);
                data.set('historico', '1');
                data.delete('page');

                const params = new URLSearchParams();

                data.forEach(function (value, key) {
                    if (String(value).trim() === '' && key !== 'historico') {
                        return;
                    }

                    params.set(key, value);
                });

                return new URL(form.action.split('#')[0] + '?' + params.toString() + '#historico-consultas', window.location.href);
            }

            if (historicoPanel) {
                historicoPanel.addEventListener('click', function (event) {
                    const link = event.target.closest('a');

                    if (!link || link.closest('.history-download-form') || link.classList.contains('disabled')) {
                        return;
                    }

                    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    const target = historicoUrlNormalizada(link.href);

                    if (!historicoAjaxAplicavel(target)) {
                        return;
                    }

                    event.preventDefault();
                    atualizarHistoricoPorAjax(target).then(function (ok) {
                        if (!ok) {
                            window.location.href = target.href;
                        }
                    });
                });

                historicoPanel.addEventListener('submit', function (event) {
                    const form = event.target.closest('.history-search');

                    if (!form) {
                        return;
                    }

                    event.preventDefault();
                    const target = historicoUrlDoFormulario(form);
                    atualizarHistoricoPorAjax(target).then(function (ok) {
                        if (!ok) {
                            form.submit();
                        }
                    });
                });

                historicoPanel.addEventListener('change', function (event) {
                    const field = event.target;
                    const form = field.closest('.history-search');

                    if (!form || field.tagName !== 'SELECT') {
                        return;
                    }

                    const target = historicoUrlDoFormulario(form);
                    atualizarHistoricoPorAjax(target).then(function (ok) {
                        if (!ok) {
                            window.location.href = target.href;
                        }
                    });
                });

                historicoPanel.addEventListener('input', function (event) {
                    const field = event.target;
                    const form = field.closest('.history-search');

                    if (!form || field.name !== 'busca') {
                        return;
                    }

                    window.clearTimeout(historicoBuscaTimer);
                    historicoBuscaTimer = window.setTimeout(function () {
                        atualizarHistoricoPorAjax(historicoUrlDoFormulario(form), { replaceState: true });
                    }, 550);
                });

                window.addEventListener('popstate', function () {
                    atualizarHistoricoPorAjax(window.location.href, { replaceState: true });
                });
            }

            const pedidoPollingIntervals = [15000, 30000, 60000];
            const pedidoPollingMaxChecks = 30;
            let pedidoPollingStep = 0;
            let pedidoPollingTimer = null;
            let pedidoPollingChecks = 0;

            function pendingHistoryItems() {
                return Array.prototype.slice.call(document.querySelectorAll('[data-history-item]')).filter(function (item) {
                    const status = item.querySelector('[data-history-status]');
                    return status && status.classList.contains('status-pendente') && item.offsetParent !== null;
                });
            }

            function mostrarAvisoPedido(item, message) {
                const main = item.querySelector('.history-main');

                if (!main || !message) {
                    return;
                }

                let aviso = item.querySelector('[data-history-live-message]');

                if (!aviso) {
                    aviso = document.createElement('span');
                    aviso.className = 'history-live-message';
                    aviso.setAttribute('data-history-live-message', '');
                    aviso.setAttribute('aria-live', 'polite');
                    main.appendChild(aviso);
                }

                aviso.textContent = message;
            }

            function statusClassFromLabel(label) {
                return String(label || 'Erro')
                    .toLowerCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9_-]/g, '-');
            }

            function atualizarStatusItem(item, label) {
                const status = item.querySelector('[data-history-status]');
                const statusClass = statusClassFromLabel(label);

                if (!status) {
                    return;
                }

                status.textContent = label || 'Erro';
                status.className = 'status-pill status-' + statusClass;
            }

            function marcarPedidoDisponivel(item, pedido) {
                const modalidade = pedido.modalidade || item.getAttribute('data-modalidade') || '';
                const action = item.querySelector('[data-history-action]');
                const consultaInput = item.querySelector('input[name="consulta_id"]');
                const url = pedido.url || '';

                atualizarStatusItem(item, pedido.status_label || 'Disponível');

                if (pedido.consulta_id) {
                    item.setAttribute('data-consulta-id', String(pedido.consulta_id));
                }

                if (consultaInput && item.dataset.consultaId) {
                    consultaInput.value = item.dataset.consultaId;
                }

                if (!action) {
                    item.removeAttribute('data-history-item');
                    return;
                }

                if (modalidade === 'pdf_processo') {
                    const form = action.closest('form');

                    if (form && url) {
                        form.action = url;
                        form.method = 'get';
                    }

                    action.textContent = pedido.button_text || 'Baixar';
                    action.disabled = false;
                    action.removeAttribute('aria-disabled');
                    action.classList.remove('history-action-disabled');
                    action.classList.add('download-ready');
                    mostrarAvisoPedido(item, 'PDF disponível para download.');
                    item.removeAttribute('data-history-item');
                    return;
                }

                const link = document.createElement('a');
                link.href = url || '#';
                link.textContent = pedido.button_text || 'Abrir';
                action.replaceWith(link);
                item.removeAttribute('data-history-item');
            }

            function marcarPedidoFinalizadoSemAcesso(item, label) {
                const action = item.querySelector('[data-history-action]');

                atualizarStatusItem(item, label || 'Erro');

                if (action && action.tagName === 'BUTTON') {
                    action.disabled = true;
                    action.setAttribute('aria-disabled', 'true');
                    action.classList.remove('download-ready');
                    action.classList.add('history-action-disabled');
                }

                item.removeAttribute('data-history-item');
            }

            function verificarPedidosPendentesEmLote(items) {
                const pendentes = (items || []).filter(function (item) {
                    return item && item.getAttribute('data-history-checking') !== '1';
                });

                if (pendentes.length === 0) {
                    return Promise.resolve(false);
                }

                const token = pendentes[0].getAttribute('data-action-token') || '';
                const pedidoIds = pendentes.map(function (item) {
                    return item.getAttribute('data-pedido-id') || '';
                }).filter(Boolean);

                if (pedidoIds.length === 0 || !token) {
                    return Promise.resolve(false);
                }

                pendentes.forEach(function (item) {
                    item.setAttribute('data-history-checking', '1');
                });

                return fetch('api/processos/status_pedidos.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        ids: pedidoIds,
                        processos_action_token: token
                    })
                })
                    .then(function (response) {
                        return response.ok ? response.json() : null;
                    })
                    .then(function (data) {
                        if (!data || !Array.isArray(data.pedidos)) {
                            return false;
                        }

                        let algumAtualizado = false;

                        data.pedidos.forEach(function (pedido) {
                            const pedidoId = String(pedido.pedido_id || '');
                            const item = pendentes.find(function (candidate) {
                                return candidate.getAttribute('data-pedido-id') === pedidoId;
                            });

                            if (!item) {
                                return;
                            }

                            if (pedido.ready) {
                                marcarPedidoDisponivel(item, pedido);
                                algumAtualizado = true;
                                return;
                            }

                            if (!pedido.pending && pedido.status && pedido.status !== 'pendente') {
                                marcarPedidoFinalizadoSemAcesso(item, pedido.status_label || 'Erro');
                                algumAtualizado = true;
                            }
                        });

                        return algumAtualizado;
                    })
                    .catch(function () {
                        return false;
                    })
                    .finally(function () {
                        pendentes.forEach(function (item) {
                            item.removeAttribute('data-history-checking');
                        });
                    });
            }

            function agendarPollingPedidosPendentes() {
                if (document.hidden) {
                    if (pedidoPollingTimer) {
                        window.clearTimeout(pedidoPollingTimer);
                        pedidoPollingTimer = null;
                    }
                    return;
                }

                const pendentes = pendingHistoryItems();

                if (pendentes.length === 0) {
                    if (pedidoPollingTimer) {
                        window.clearTimeout(pedidoPollingTimer);
                        pedidoPollingTimer = null;
                    }
                    return;
                }

                if (pedidoPollingChecks >= pedidoPollingMaxChecks) {
                    if (pedidoPollingTimer) {
                        window.clearTimeout(pedidoPollingTimer);
                        pedidoPollingTimer = null;
                    }
                    return;
                }

                const delay = pedidoPollingIntervals[Math.min(pedidoPollingStep, pedidoPollingIntervals.length - 1)];

                pedidoPollingTimer = window.setTimeout(function () {
                    pedidoPollingChecks += 1;
                    verificarPedidosPendentesEmLote(pendingHistoryItems()).then(function (algumAtualizado) {
                        pedidoPollingStep = algumAtualizado ? 0 : Math.min(pedidoPollingStep + 1, pedidoPollingIntervals.length - 1);
                    }).finally(agendarPollingPedidosPendentes);
                }, delay);
            }

            agendarPollingPedidosPendentes();

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    if (pedidoPollingTimer) {
                        window.clearTimeout(pedidoPollingTimer);
                        pedidoPollingTimer = null;
                    }
                    return;
                }

                pedidoPollingStep = 0;
                agendarPollingPedidosPendentes();
            });

            document.querySelectorAll('[data-loading-close]').forEach(function (button) {
                button.addEventListener('click', hideProcessosLoading);
            });

            if (creditModal && creditModalOpen) {
                function openCreditModal() {
                    creditModal.hidden = false;
                    document.body.classList.add('modal-open');
                }

                function closeCreditModal() {
                    creditModal.hidden = true;
                    document.body.classList.remove('modal-open');
                }

                window.bidmapOpenCreditModal = openCreditModal;
                window.bidmapCloseCreditModal = closeCreditModal;

                creditModalOpen.addEventListener('click', openCreditModal);

                creditModalClose.forEach(function (button) {
                    button.addEventListener('click', closeCreditModal);
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' && !creditModal.hidden) {
                        closeCreditModal();
                    }
                });
            }

            updateActions();

            if (erroInicial) {
                const erroNormalizado = String(erroInicial || '').toLowerCase();
                const isSaldoInsuficiente = erroNormalizado.indexOf('créditos insuficientes') !== -1 ||
                    erroNormalizado.indexOf('creditos insuficientes') !== -1;

                openConsultaModal(
                    isSaldoInsuficiente ? 'Créditos insuficientes' : 'Não foi possível concluir a consulta',
                    erroInicial,
                    isSaldoInsuficiente
                        ? { infoOnly: true, messageHtml: escapeHtml(erroInicial) + '<a href="#" data-insufficient-add-credits>Adicionar cr&eacute;ditos</a>' }
                        : { infoOnly: true }
                );
            }

            if (pdfProcessandoMensagem) {
                limparPdfStatusDaUrl();
                openConsultaModal('Seu processo está em andamento', pdfProcessandoMensagem, {
                    infoOnly: true,
                    locked: true,
                    hideCancel: true,
                    openSavedUrl: 'consultar_processos.php',
                    openSavedText: 'Entendi',
                    openSavedPrimary: true
                });
            }
        });
    </script>
</body>

</html>

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/database/consulta_tables.php';
require_once __DIR__ . '/services/ProcessoService.php';
require_once __DIR__ . '/database/models/CustoConsultaModel.php';
require_once __DIR__ . '/helpers/processos_actions.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/credit_modal.php';
require_once __DIR__ . '/helpers/account_menu.php';
require_once __DIR__ . '/helpers/demo_data.php';

bidmap_require_login_for_creditos();

if (false && function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode()) {
    $consulta = bidmap_demo_find_consulta((int) ($_GET['consulta_id'] ?? 0));
    $entrada = (string) ($_GET['q'] ?? ($consulta['entrada_original'] ?? '1000000-00.2024.8.26.0100'));

    bidmap_demo_render_page(
        'Detalhes do processo - Demo',
        'Resumo mockado de partes, movimentacoes e documentos processuais.',
        [
            'Numero do processo' => $entrada,
            'Classe' => 'Procedimento Comum Civel',
            'Tribunal' => 'TJSP',
            'Parte autora' => 'Pessoa Demo',
            'Parte requerida' => 'Empresa Demo LTDA',
            'Ultima movimentacao' => 'Juntada de peticao em modo demo',
            'Documentos' => 'Peticao inicial, decisao e certidao demonstrativas',
        ]
    );
}

function detalhe_env_bool(string $key, bool $default = false): bool
{
    $value = bidmap_env($key);

    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function detalhe_db(): mysqli
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

function detalhe_creditos(): UsuarioCreditoService
{
    static $service = null;

    if ($service instanceof UsuarioCreditoService) {
        return $service;
    }

    if (!detalhe_env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false)) {
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

function detalhe_creditos_disponiveis(): float
{
    try {
        $service = detalhe_creditos();
        return $service->getSaldo($service->getUsuarioId());
    } catch (Throwable $exception) {
        return 0.0;
    }
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function detalhe_return_url_segura(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'consultar_processos.php';
    }

    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) || str_starts_with($value, '//')) {
        return 'consultar_processos.php';
    }

    $path = parse_url($value, PHP_URL_PATH);
    $base = basename((string) $path);
    $permitidos = [
        'consultar_processos.php',
        'resultado_processos_documento.php',
        'historico_consultas.php',
    ];

    if (!in_array($base, $permitidos, true)) {
        return 'consultar_processos.php';
    }

    return $value;
}

function detalhe_return_label(string $returnUrl): string
{
    $base = basename((string) parse_url($returnUrl, PHP_URL_PATH));

    return $base === 'resultado_processos_documento.php'
        ? 'Processos'
        : 'Consulta de processos';
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

function first_array(array $data, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_values(array_filter($data[$key], 'is_array'));
        }
    }

    return [];
}

function processo_payload(array $raw): array
{
    foreach (['processo', 'data', 'result'] as $key) {
        if (isset($raw[$key]) && is_array($raw[$key])) {
            return $raw[$key];
        }
    }

    return $raw;
}

function detalhe_extract_list(array $raw): array
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

function detalhe_processo_matches(array $processo, string $numeroNormalizado): bool
{
    if ($numeroNormalizado === '') {
        return false;
    }

    $numero = first_value($processo, [
        'number',
        'cnjMasked',
        'cnjOnlyNumbers',
        'numero',
        'numero_processo',
        'numeroProcesso',
        'cnj',
    ], '');

    return detalhe_only_digits($numero) === $numeroNormalizado;
}

function detalhe_only_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function detalhe_format_cnj(?string $value): string
{
    $digits = detalhe_only_digits($value);

    if (strlen($digits) !== 20) {
        return (string) $value;
    }

    return preg_replace('/^(\d{7})(\d{2})(\d{4})(\d{1})(\d{2})(\d{4})$/', '$1-$2.$3.$4.$5.$6', $digits);
}

function detalhe_format_money(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '' || $value === '-' || $value === '-1') {
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
        return $value;
    }

    return 'R$ ' . number_format((float) $normalized, 2, ',', '.');
}

function detalhe_format_date(?string $value, bool $withTime = false): string
{
    $value = trim((string) $value);

    if ($value === '' || $value === '-') {
        return 'Não informado';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}

function detalhe_value(array $processo, array $keys, string $default = 'Não informado', string $format = 'text'): string
{
    $value = first_value($processo, $keys, $default);

    if ($format === 'money') {
        return detalhe_format_money($value);
    }

    if ($format === 'date') {
        return detalhe_format_date($value);
    }

    if ($format === 'datetime') {
        return detalhe_format_date($value, true);
    }

    return (string) ($value ?? $default);
}

function detalhe_int_value(array $processo, array $keys): int
{
    foreach ($keys as $key) {
        if (!isset($processo[$key]) || is_array($processo[$key])) {
            continue;
        }

        $value = trim((string) $processo[$key]);

        if ($value === '') {
            continue;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits !== '') {
            return (int) $digits;
        }
    }

    return 0;
}

function detalhe_format_instancia(string $value): string
{
    $value = trim($value);

    if ($value === '' || $value === '-' || $value === 'Não informado') {
        return 'Não informado';
    }

    if (preg_match('/^\d+$/', $value)) {
        return $value . 'ª';
    }

    return $value;
}

function detalhe_area_direito(array $processo): string
{
    $area = detalhe_value($processo, ['lawArea', 'legalArea', 'area_direito', 'area']);

    if ($area !== 'Não informado') {
        return $area;
    }

    $assuntoAmplo = detalhe_value($processo, ['iBroadCNJSubjectName']);

    if ($assuntoAmplo === 'Não informado') {
        return $assuntoAmplo;
    }

    $partes = preg_split('/\s+-\s+/', $assuntoAmplo) ?: [];
    $primeiroNivel = trim((string) reset($partes));
    $primeiroNivel = preg_replace('/\s*\(\d+\)\s*$/', '', $primeiroNivel) ?? $primeiroNivel;

    return $primeiroNivel !== '' ? $primeiroNivel : 'Não informado';
}

function detalhe_extract_movements(array $processo): array
{
    foreach (['movements', 'movimentacoes', 'movimentos', 'andamentos'] as $key) {
        if (!isset($processo[$key]) || !is_array($processo[$key])) {
            continue;
        }

        $movements = [];

        foreach ($processo[$key] as $item) {
            if (is_array($item)) {
                if (isset($item['updates']) && is_array($item['updates'])) {
                    foreach ($item['updates'] as $update) {
                        if (!is_array($update)) {
                            continue;
                        }

                        $update['groupDate'] = $item['date'] ?? null;
                        $movements[] = $update;
                    }

                    continue;
                }

                $movements[] = $item;
                continue;
            }

            if (is_scalar($item) && trim((string) $item) !== '') {
                $movements[] = ['text' => (string) $item];
            }
        }

        return $movements;
    }

    return [];
}

function detalhe_extract_documents(array $processo): array
{
    foreach ([
        'documents',
        'documentos',
        'attachments',
        'anexos',
        'files',
        'arquivos',
        'mainDocuments',
        'primaryDocuments',
        'lawsuitDocuments',
    ] as $key) {
        if (isset($processo[$key]) && is_array($processo[$key])) {
            return array_values(array_filter($processo[$key], 'is_array'));
        }
    }

    return [];
}

function detalhe_movement_raw_date(array $movimentacao): string
{
    return first_value($movimentacao, [
        'publishDate',
        'publishedAt',
        'date',
        'movementDate',
        'movement_date',
        'data',
        'data_movimentacao',
        'groupDate',
        'publicationDate',
        'createdAt',
        'created_at',
        'updatedAt',
        'updated_at',
    ], '') ?? '';
}

function detalhe_movement_date(array $movimentacao): string
{
    return detalhe_format_date(detalhe_movement_raw_date($movimentacao));
}

function detalhe_movement_text(array $movimentacao): string
{
    $title = first_value($movimentacao, ['title', 'titulo'], '');
    $description = first_value($movimentacao, ['description', 'descricao'], '');

    if ($title !== '' && $description !== '' && $description !== $title) {
        return $title . ' - ' . $description;
    }

    return first_value($movimentacao, [
        'title',
        'titulo',
        'text',
        'description',
        'descricao',
        'content',
        'conteudo',
        'movement',
        'movimento',
        'name',
    ], 'Não informado') ?? 'Não informado';
}

function detalhe_sort_movements(array $movimentacoes, string $ordem = 'desc'): array
{
    usort($movimentacoes, function (array $a, array $b) use ($ordem): int {
        $timeA = strtotime(detalhe_movement_raw_date($a)) ?: 0;
        $timeB = strtotime(detalhe_movement_raw_date($b)) ?: 0;

        return $ordem === 'asc' ? $timeA <=> $timeB : $timeB <=> $timeA;
    });

    return $movimentacoes;
}

function detalhe_document_title(array $documento): string
{
    return first_value($documento, ['name', 'nome', 'title', 'titulo', 'type', 'tipo'], 'Documento') ?? 'Documento';
}

function detalhe_document_meta(array $documento): string
{
    $tipo = first_value($documento, ['format', 'mimeType', 'extension', 'extensao'], 'PDF') ?? 'PDF';
    $data = detalhe_format_date(first_value($documento, ['date', 'data', 'createdAt', 'created_at', 'publishDate'], ''));

    return strtoupper($tipo) . ($data !== 'Não informado' ? ' • ' . $data : '');
}

function detalhe_document_url(array $documento): string
{
    return first_value($documento, ['url', 'downloadUrl', 'download_url', 'link', 'href'], '#') ?? '#';
}

function detalhe_assunto_curto(string $assunto): string
{
    $assunto = trim($assunto);

    if ($assunto === '' || $assunto === '-') {
        return '-';
    }

    $partes = preg_split('/\s+-\s+/', $assunto) ?: [];
    $ultimoNivel = trim((string) end($partes));

    if ($ultimoNivel !== '') {
        $assunto = $ultimoNivel;
    }

    $assunto = preg_replace('/\s*\(\d+\)?\s*$/', '', $assunto) ?? $assunto;
    $assunto = trim($assunto);

    if (function_exists('mb_strlen') && mb_strlen($assunto, 'UTF-8') > 96) {
        return rtrim(mb_substr($assunto, 0, 96, 'UTF-8')) . '...';
    }

    if (strlen($assunto) > 96) {
        return rtrim(substr($assunto, 0, 96)) . '...';
    }

    return $assunto;
}

function detalhe_buscar_processo_no_cache(mysqli $mysqli, int $consultaId, string $numeroProcesso): ?array
{
    if ($consultaId <= 0) {
        return null;
    }

    $model = new ConsultaExternaModel($mysqli);
    $consulta = $model->buscarPorId($consultaId);

    if (!$consulta || ($consulta['status_resultado'] ?? '') !== 'sucesso') {
        return null;
    }

    $dados = json_decode((string) ($consulta['dados_json'] ?? ''), true);

    if (!is_array($dados)) {
        return null;
    }

    $numeroNormalizado = detalhe_only_digits($numeroProcesso);

    $processoDireto = processo_payload($dados);

    if (detalhe_processo_matches($processoDireto, $numeroNormalizado)) {
        return [
            'ok' => true,
            'origem' => 'cache',
            'consulta_id' => $consultaId,
            'dados' => $processoDireto,
        ];
    }

    foreach (detalhe_extract_list($dados) as $processo) {
        if (detalhe_processo_matches($processo, $numeroNormalizado)) {
            return [
                'ok' => true,
                'origem' => 'cache',
                'consulta_id' => $consultaId,
                'dados' => $processo,
            ];
        }
    }

    return null;
}

function detalhe_buscar_processo_em_cache_por_numero(mysqli $mysqli, string $numeroProcesso): ?array
{
    $numeroNormalizado = detalhe_only_digits($numeroProcesso);

    if ($numeroNormalizado === '') {
        return null;
    }

    $like = '%' . $mysqli->real_escape_string($numeroNormalizado) . '%';
    $consultasExternas = consulta_table('consultas_externas');
    $sql = "SELECT id_consulta, dados_json
            FROM {$consultasExternas}
            WHERE modalidade_pedido IN ('consulta_processos', 'detalhes_processo')
              AND status_resultado = 'sucesso'
              AND dados_json LIKE ?
            ORDER BY data_resposta DESC, id_consulta DESC
            LIMIT 20";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($consulta = $result->fetch_assoc()) {
        $dados = json_decode((string) ($consulta['dados_json'] ?? ''), true);

        if (!is_array($dados)) {
            continue;
        }

        $processoDireto = processo_payload($dados);

        if (detalhe_processo_matches($processoDireto, $numeroNormalizado)) {
            $stmt->close();

            return [
                'ok' => true,
                'origem' => 'cache',
                'consulta_id' => (int) $consulta['id_consulta'],
                'dados' => $processoDireto,
            ];
        }

        foreach (detalhe_extract_list($dados) as $processo) {
            if (detalhe_processo_matches($processo, $numeroNormalizado)) {
                $stmt->close();

                return [
                    'ok' => true,
                    'origem' => 'cache',
                    'consulta_id' => (int) $consulta['id_consulta'],
                    'dados' => $processo,
                ];
            }
        }
    }

    $stmt->close();
    return null;
}

function detalhe_registrar_consulta_direta_cache(
    mysqli $mysqli,
    array $resultado,
    string $entradaOriginal,
    string $entradaNormalizada,
    UsuarioCreditoService $creditoService
): array {
    $tipoPedidoModel = new TipoPedidoModel($mysqli);
    $pedidoModel = new PedidoUsuarioModel($mysqli);
    $tipoPedido = $tipoPedidoModel->buscarPorModalidade('detalhes_processo');

    if (!$tipoPedido) {
        return [
            'ok' => false,
            'message' => 'Modalidade de consulta de processos não configurada.',
        ];
    }

    $idUsuario = $creditoService->getUsuarioId();
    $saldoAnterior = $creditoService->getSaldo($idUsuario);

    $pedidoId = $pedidoModel->criarPendente(
        $idUsuario,
        (int) $tipoPedido['id_tipo_pedido'],
        'detalhes_processo',
        $entradaOriginal,
        $entradaNormalizada,
        0.0,
        $saldoAnterior,
        $saldoAnterior
    );

    $pedidoModel->marcarSucessoCache(
        $pedidoId,
        (int) ($resultado['consulta_id'] ?? 0),
        $tipoPedido['fornecedor_padrao'] ?: 'consultaprocesso'
    );

    return [
        'ok' => true,
        'pedido_id' => $pedidoId,
    ];
}

function detalhe_atualizar_consulta_web(mysqli $mysqli, int $consultaId): ?string
{
    if ($consultaId <= 0) {
        return null;
    }

    $model = new ConsultaExternaModel($mysqli);
    $consulta = $model->buscarPorId($consultaId);

    if (!$consulta || !in_array(($consulta['modalidade_pedido'] ?? ''), ['consulta_processos', 'detalhes_processo'], true)) {
        return null;
    }

    $dados = json_decode((string) ($consulta['dados_json'] ?? ''), true);
    $requestId = is_array($dados)
        ? ($dados['requestId'] ?? $dados['createResponse']['requestId'] ?? $dados['resultResponse']['requestId'] ?? null)
        : null;

    if (!$requestId) {
        return 'Não foi possível atualizar: requestId não encontrado no cache.';
    }

    return 'Atualizacao direta desativada. Crie uma nova consulta para processar pela fila.';

    if (!$resultado['ok']) {
        return $resultado['message'] ?: 'Não foi possível atualizar a consulta na web.';
    }

    $status = strtolower((string) ($resultado['raw']['status'] ?? 'done'));

    if (in_array($status, ['fetching', 'pending', 'running', 'processing'], true)) {
        $model->marcarPendenteProcessamento($consultaId, $resultado['message'], [
            'requestId' => $requestId,
            'resultResponse' => $resultado['raw'],
        ]);
        return 'A atualização ainda está em processamento. Recarregue em alguns segundos.';
    }

    $model->marcarSucesso(
        $consultaId,
        [
            'message' => $resultado['message'],
            'raw' => array_merge($resultado['raw'], ['requestId' => $requestId]),
        ],
        (int) ($consulta['creditos_externo_consumido'] ?? 0),
        $consulta['data_validade'] ?? date('Y-m-d H:i:s', strtotime('+30 days'))
    );

    return null;
}

function detalhe_url(string $secao): string
{
    $params = $_GET;
    $params['secao'] = $secao;
    unset($params['acao']);
    unset($params['atualizar']);

    return 'detalhe_processo.php?' . http_build_query($params);
}

function detalhe_pdf_url(string $numeroProcesso): string
{
    $returnParams = $_GET;
    unset($returnParams['pdf_status'], $returnParams['pdf_message']);

    return 'api/processos/documentos/pdf.php?' . http_build_query([
        'numero' => $numeroProcesso,
        'return' => 'detalhe_processo.php?' . http_build_query($returnParams),
    ]);
}

function detalhe_ordem_url(string $ordem): string
{
    $params = $_GET;
    $params['secao'] = 'movimentacoes';
    $params['ordem'] = $ordem;
    unset($params['mov_page']);
    unset($params['acao']);
    unset($params['atualizar']);

    return 'detalhe_processo.php?' . http_build_query($params);
}

function detalhe_movimentacoes_page_url(int $page): string
{
    $params = $_GET;
    $params['secao'] = 'movimentacoes';
    $params['mov_page'] = max(1, $page);
    unset($params['acao']);
    unset($params['atualizar']);

    return 'detalhe_processo.php?' . http_build_query($params);
}

function detalhe_consulta_completa_url(string $numeroProcesso, string $secao = 'geral'): string
{
    $params = [
        'q' => detalhe_format_cnj($numeroProcesso),
        'acao' => 'consultar_processo',
        'secao' => $secao,
    ];

    $returnUrl = detalhe_return_url_segura($_GET['return'] ?? '');
    if ($returnUrl !== 'consultar_processos.php') {
        $params['return'] = $returnUrl;
    }

    return 'detalhe_processo.php?' . http_build_query($params);
}

function detalhe_format_creditos(float $creditos): string
{
    return number_format($creditos, 2, '.', '');
}

function detalhe_is_consulta_completa(array $processo): bool
{
    foreach (['cnjMasked', 'cnjOnlyNumbers', 'totalUpdates', 'detailSource'] as $key) {
        if (isset($processo[$key]) && $processo[$key] !== '') {
            return true;
        }
    }

    if (!empty($processo['movements']) && is_array($processo['movements'])) {
        foreach ($processo['movements'] as $movementGroup) {
            if (is_array($movementGroup) && isset($movementGroup['updates']) && is_array($movementGroup['updates'])) {
                return true;
            }
        }
    }

    return false;
}

function detalhe_menu_link(string $secaoAtual, string $secao, string $icone, string $texto): void
{
    $classes = $secaoAtual === $secao ? 'active' : '';

    echo '<a class="' . h($classes) . '" href="' . h(detalhe_url($secao)) . '" data-section-link="' . h($secao) . '">';
    echo '<i class="' . h($icone) . '" aria-hidden="true"></i> ' . h($texto);
    echo '</a>';
}

function detalhe_field_value(string $label, string $value, string $tooltip = ''): void
{
    echo '<div class="detail-field">';
    echo '<span>' . h($label);
    if ($tooltip !== '') {
        echo ' <i class="fa-regular fa-circle-question field-help" title="' . h($tooltip) . '" aria-label="' . h($tooltip) . '"></i>';
    }
    echo '</span>';
    echo '<strong>' . h($value) . '</strong>';
    echo '</div>';
}

function detalhe_movement_source(array $movimentacao): string
{
    $source = trim((string) first_value($movimentacao, [
        'sourceName',
        'provider',
        'crawler',
        'source',
        'origin',
        'origem',
    ], ''));

    if ($source === '') {
        return '';
    }

    $normalized = strtolower($source);
    $map = [
        'c' => 'CODILO',
        'codilo' => 'CODILO',
        'b' => 'BIGDATA',
        'bigdata' => 'BIGDATA',
    ];

    return $map[$normalized] ?? $source;
}

$creditos = detalhe_creditos_disponiveis();
$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$acaoConsultaDireta = ($request['acao'] ?? '') === 'consultar_processo';
$acaoTokenValido = !$acaoConsultaDireta
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && processos_validate_action_token($request['processos_action_token'] ?? null));
$numeroProcesso = $request['q'] ?? '';
$returnUrl = detalhe_return_url_segura($request['return'] ?? ($_GET['return'] ?? ''));
$returnLabel = detalhe_return_label($returnUrl);
$consultaIdOrigem = (int) ($request['consulta_id'] ?? ($_GET['consulta_id'] ?? 0));
$secoesPermitidas = ['geral', 'detalhes', 'movimentacoes', 'partes', 'documentos'];
$secaoAtiva = strtolower((string) ($request['secao'] ?? ($_GET['secao'] ?? 'geral')));
$secaoAtiva = in_array($secaoAtiva, $secoesPermitidas, true) ? $secaoAtiva : 'geral';
$ordemMovimentacoes = strtolower((string) ($_GET['ordem'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$movimentacoesPorPagina = 20;
$paginaMovimentacoes = max(1, (int) ($_GET['mov_page'] ?? 1));
$resultado = null;
$erro = null;
$aviso = null;
$creditosConsultaCompleta = 0.0;
$creditosPdfProcesso = 0.0;
$consultaDireta = $acaoConsultaDireta;
$isPortfolioDemo = function_exists('bidmap_portfolio_demo_mode') && bidmap_portfolio_demo_mode();

if ($isPortfolioDemo) {
    $consultaDemo = bidmap_demo_find_consulta($consultaIdOrigem);
    $numeroProcesso = (string) ($numeroProcesso ?: ($consultaDemo['entrada_original'] ?? '1000000-00.2024.8.26.0100'));
    $creditosConsultaCompleta = 3.0;
    $creditosPdfProcesso = 12.0;
    $resultado = [
        'ok' => true,
        'origem' => 'portfolio_demo',
        'consulta_id' => $consultaIdOrigem ?: 9004,
        'dados' => bidmap_demo_processo_detalhe((string) $numeroProcesso),
    ];
} else {
try {
    $mysqli = detalhe_db();
    $tipoPedidoModel = new TipoPedidoModel($mysqli);
    $custoConsultaModel = new CustoConsultaModel($mysqli);
    $tipoPedidoConsultaProcessos = $tipoPedidoModel->buscarPorModalidade('detalhes_processo');
    $custoDetalhesProcesso = $custoConsultaModel->buscarPorChave('detalhes_processo');

    if ($custoDetalhesProcesso) {
        $creditosConsultaCompleta = (float) ($custoDetalhesProcesso['creditos_usuario'] ?? 0);
    } elseif ($tipoPedidoConsultaProcessos) {
        $creditosConsultaCompleta = (float) ($tipoPedidoConsultaProcessos['creditos_usuario'] ?? 0);
    }

    $tipoPedidoPdfProcesso = $tipoPedidoModel->buscarPorModalidade('pdf_processo');
    $custoPdfProcesso = $custoConsultaModel->buscarPorChave('pdf_processo');

    if ($custoPdfProcesso) {
        $creditosPdfProcesso = (float) ($custoPdfProcesso['creditos_usuario'] ?? 0);
    } elseif ($tipoPedidoPdfProcesso) {
        $creditosPdfProcesso = (float) ($tipoPedidoPdfProcesso['creditos_usuario'] ?? 0);
    }

    $consultaDireta = $acaoConsultaDireta;

    if ($consultaDireta && !$acaoTokenValido) {
        $resultado = [
            'ok' => false,
            'message' => 'Ação expirada. Volte para a listagem e tente novamente.',
        ];
    } elseif ($consultaDireta) {
        $service = new ProcessoService($mysqli, null, detalhe_creditos());
        $resultado = $service->buscarPorCnj((string) $numeroProcesso, null, ($request['atualizar'] ?? '') === '1');
    }

    if (!$resultado) {
        $resultado = detalhe_buscar_processo_no_cache($mysqli, $consultaIdOrigem, $numeroProcesso);
    }

    if (!$resultado) {
        $resultado = detalhe_buscar_processo_em_cache_por_numero($mysqli, $numeroProcesso);
    }

    if ($consultaDireta && $resultado && !empty($resultado['ok'])) {
        $entradaNormalizada = detalhe_only_digits($numeroProcesso);
        $redirectParams = [
            'q' => $entradaNormalizada !== '' ? detalhe_format_cnj($entradaNormalizada) : $numeroProcesso,
            'consulta_id' => (int) ($resultado['consulta_id'] ?? 0),
        ];
        if ($returnUrl !== 'consultar_processos.php') {
            $redirectParams['return'] = $returnUrl;
        }
        if (isset($request['secao']) && in_array((string) $request['secao'], $secoesPermitidas, true)) {
            $redirectParams['secao'] = (string) $request['secao'];
        }
        if (isset($request['ordem'])) {
            $redirectParams['ordem'] = (string) $request['ordem'];
        }
        $mysqli->close();
        header('Location: detalhe_processo.php?' . http_build_query($redirectParams));
        exit;

        $registro = detalhe_registrar_consulta_direta_cache(
            $mysqli,
            $resultado,
            trim((string) $numeroProcesso),
            $entradaNormalizada,
            detalhe_creditos()
        );

        $registro = ['ok' => true];

        if (!$registro['ok']) {
            $erro = $registro['message'] ?? 'Não foi possível registrar a consulta.';
        } else {
            $redirectParams = [
                'q' => $entradaNormalizada !== '' ? detalhe_format_cnj($entradaNormalizada) : $numeroProcesso,
                'consulta_id' => (int) ($resultado['consulta_id'] ?? 0),
            ];
            if ($returnUrl !== 'consultar_processos.php') {
                $redirectParams['return'] = $returnUrl;
            }
            $mysqli->close();
            header('Location: detalhe_processo.php?' . http_build_query($redirectParams));
            exit;
        }
    }

    $mysqli->close();

    if (!$resultado) {
        $resultado = [
            'ok' => false,
            'message' => 'Não encontrei este processo no cache local. Pesquise primeiro por CPF/CNPJ ou abra o processo a partir da listagem.',
        ];
    }

    if (!$resultado['ok']) {
        $erro = $resultado['message'] ?? 'Não foi possível consultar o processo.';
    }
} catch (Throwable $exception) {
    $erro = $exception->getMessage();
}
}

if ($consultaDireta && $erro) {
    if ($returnUrl === 'consultar_processos.php') {
        header('Location: consultar_processos.php?' . http_build_query([
            'consulta_erro' => $erro,
        ]));
        exit;
    }
}

$raw = is_array($resultado['dados'] ?? null) ? $resultado['dados'] : [];
$processo = $erro ? [] : processo_payload($raw);
$movimentacoes = detalhe_sort_movements(detalhe_extract_movements($processo), $ordemMovimentacoes);
$totalMovimentacoesRenderizadas = count($movimentacoes);
$totalPaginasMovimentacoes = max(1, (int) ceil($totalMovimentacoesRenderizadas / $movimentacoesPorPagina));
$paginaMovimentacoes = min($paginaMovimentacoes, $totalPaginasMovimentacoes);
$movimentacoesPagina = array_slice($movimentacoes, ($paginaMovimentacoes - 1) * $movimentacoesPorPagina, $movimentacoesPorPagina);
$partes = first_array($processo, ['parties', 'partes', 'envolvidos', 'partes_envolvidas']);
$documentos = detalhe_extract_documents($processo);
$consultaPendente = !empty($resultado['pendente']);

$numero = first_value($processo, ['number', 'cnjMasked', 'cnjOnlyNumbers', 'numero', 'numero_processo', 'numeroProcesso', 'cnj'], $numeroProcesso);
$titulo = first_value($processo, ['titulo', 'nome', 'partes', 'resumo'], 'Partes do processo');
$autorTitulo = first_value($processo, ['author', 'autor'], '');
$reuTitulo = first_value($processo, ['reu', 'defendant', 'réu'], '');

if ($autorTitulo !== '' || $reuTitulo !== '') {
    $titulo = trim($autorTitulo . ($autorTitulo !== '' && $reuTitulo !== '' ? ' x ' : '') . $reuTitulo);
}

$tribunal = first_value($processo, ['courtName', 'tribunal', 'orgao_julgador', 'foro'], 'Tribunal não informado');
$local = first_value($processo, ['courtDistrict', 'local', 'comarca', 'state', 'estado', 'uf'], '');
$assuntoPrincipal = detalhe_value($processo, ['mainSubject', 'iCNJSubjectName', 'assunto', 'assunto_principal']);
$assuntoPrincipalCurto = detalhe_assunto_curto($assuntoPrincipal);
$areaDireito = detalhe_area_direito($processo);
$assuntoAmplo = detalhe_value($processo, ['iBroadCNJSubjectName', 'broadSubject', 'assunto_amplo']);
$valorCausa = detalhe_value($processo, ['value', 'claimValue', 'amount', 'valor_causa', 'valorDaCausa', 'valor'], 'Não informado', 'money');
$dataInicio = detalhe_value($processo, ['distributionDate', 'filingDate', 'startDate', 'noticeDate', 'createdAt', 'data_distribuicao', 'inicio_processo', 'data_inicio'], 'Não informado', 'date');
$ultimaAtualizacao = detalhe_value($processo, ['lastUpdate', 'lastMovementDate', 'last_update', 'updated_at', 'ultima_atualizacao'], 'Não informado', 'datetime');
$ultimaVerificacao = date('d/m/Y H:i');
$statusProcesso = detalhe_value($processo, ['statusName', 'status_name', 'lawsuitStatus', 'lawsuit_status', 'situationName', 'situation', 'status', 'situacao', 'situacao_processual']);
$instanciaProcesso = detalhe_format_instancia(detalhe_value($processo, ['instance', 'courtLevel', 'instancia', 'grau']));
$totalMovimentacoesInformado = detalhe_int_value($processo, ['numberOfUpdates', 'totalUpdates', 'numero_atualizacoes']);
$totalPartesInformado = detalhe_int_value($processo, ['numberOfParties', 'totalParties', 'numero_partes']);
$totalMovimentacoes = max(count($movimentacoes), $totalMovimentacoesInformado);
$totalPartes = max(count($partes), $totalPartesInformado);
$pdfDownloadUrl = detalhe_pdf_url(detalhe_format_cnj($numero));
$pdfStatus = $_GET['pdf_status'] ?? '';
$pdfMessage = $_GET['pdf_message'] ?? '';
$consultaCompleta = detalhe_is_consulta_completa($processo);
$consultaResumoLimitado = !$consultaCompleta && !$erro;
$movimentacoesResumoJaCompleto = $consultaResumoLimitado && $totalMovimentacoesInformado > 0 && count($movimentacoes) >= $totalMovimentacoesInformado;
$partesResumoJaCompleto = $consultaResumoLimitado && $totalPartesInformado > 0 && count($partes) >= $totalPartesInformado;
$movimentacoesStatusMensagem = '';

if ($consultaCompleta) {
    $movimentacoesStatusMensagem = 'Processo completo carregado: as movimentações disponíveis na consulta atual já estão sendo exibidas.';
} elseif ($movimentacoesResumoJaCompleto) {
    $movimentacoesStatusMensagem = 'Este resumo já contém todas as movimentações informadas pela API para este processo.';
} elseif ($consultaResumoLimitado) {
    $movimentacoesMostradas = count($movimentacoes);
    $movimentacoesTotalTexto = $totalMovimentacoes > $movimentacoesMostradas
        ? ' de ' . $totalMovimentacoes
        : '';
    $movimentacoesStatusMensagem = 'Prévia salva: exibindo ' . $movimentacoesMostradas . $movimentacoesTotalTexto . ' movimentações. Para trazer todas as movimentações disponíveis, clique em Carregar todas.';
}

$creditosConsultaCompletaLabel = detalhe_format_creditos($creditosConsultaCompleta);
$creditosPdfProcessoLabel = detalhe_format_creditos($creditosPdfProcesso);
$urlConsultaCompletaMovimentacoes = detalhe_consulta_completa_url($numero, 'movimentacoes');
$urlConsultaCompletaPartes = detalhe_consulta_completa_url($numero, 'partes');
$actionToken = processos_action_token();
$pdfFormId = 'download-pdf-' . md5((string) $numero);
$completeMovFormId = 'complete-movements-' . md5((string) $numero);
$completePartesFormId = 'complete-parties-' . md5((string) $numero);
$pdfReturnParams = $_GET;
unset($pdfReturnParams['pdf_status'], $pdfReturnParams['pdf_message']);
$pdfReturnUrl = 'detalhe_processo.php?' . http_build_query($pdfReturnParams);

if (empty($partes)) {
    foreach ([['nome' => $autorTitulo, 'tipo' => 'Autor'], ['nome' => $reuTitulo, 'tipo' => 'Réu']] as $parteFallback) {
        if ($parteFallback['nome'] !== '') {
            $partes[] = $parteFallback;
        }
    }
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
    <title>Detalhe do Processo</title>
    <?php if ($consultaPendente): ?>
        <meta http-equiv="refresh" content="8">
    <?php endif; ?>
</head>

<body>
    <div id="main">
        <?php include_once 'header/header.php'; ?>

        <main class="detalhe-page">
            <section class="resultado-toolbar" aria-label="Navegação do processo">
                <div class="resultado-breadcrumb">
                    <a href="<?= h($returnUrl); ?>">
                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        <?= h($returnLabel); ?>
                    </a>
                    <span></span>
                    <strong>Detalhe do processo</strong>
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
                    <strong><?= h(detalhe_format_creditos($creditos)); ?></strong>
                    Créditos
                    <span class="credit-add">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </span>
                    </button>
                </div>
            </section>

            <section class="process-detail-wrap">
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
                    <?php if ($aviso): ?>
                        <div class="dados-alert">
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                            <div>
                                <strong>Atualização da consulta</strong>
                                <p><?= h($aviso); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($pdfStatus === 'processing'): ?>
                        <div class="dados-alert loading-alert" role="status" aria-live="polite">
                            <span class="loading-spinner" aria-hidden="true"></span>
                            <div>
                                <strong>PDF em processamento</strong>
                                <p><?= h($pdfMessage ?: 'A requisição do PDF foi enviada. Tente baixar novamente em alguns instantes.'); ?></p>
                            </div>
                        </div>
                    <?php elseif ($pdfStatus === 'error'): ?>
                        <div class="dados-alert">
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                            <div>
                                <strong>Não foi possível baixar o PDF</strong>
                                <p><?= h($pdfMessage ?: 'Tente novamente em alguns instantes.'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <header class="process-detail-header">
                        <div>
                            <p><?= h(detalhe_format_cnj($numero)); ?></p>
                            <h1><?= h($titulo); ?></h1>
                            <div class="court-line">
                                <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                                <?= h(trim($tribunal . ($local !== '' ? ' | ' . $local : ''))); ?>
                            </div>
                        </div>
                        <button class="report-btn js-paid-confirm"
                            type="button"
                            data-form-id="<?= h($pdfFormId); ?>"
                            data-title="Baixar processo na &iacute;ntegra: <?= h(detalhe_format_cnj($numero)); ?>"
                            data-message="Ser&atilde;o gastos"
                            data-credit-label="<?= h($creditosPdfProcessoLabel); ?>">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            Baixar na íntegra
                        </button>
                    </header>

                    <form id="<?= h($pdfFormId); ?>" method="post" action="api/processos/documentos/pdf.php" hidden>
                        <input type="hidden" name="numero" value="<?= h($numero); ?>">
                        <input type="hidden" name="return" value="<?= h($pdfReturnUrl); ?>">
                        <input type="hidden" name="processos_action_token" value="<?= h($actionToken); ?>">
                    </form>
                    <form id="<?= h($completeMovFormId); ?>" method="post" action="detalhe_processo.php" hidden>
                        <input type="hidden" name="q" value="<?= h($numero); ?>">
                        <input type="hidden" name="acao" value="consultar_processo">
                        <input type="hidden" name="secao" value="movimentacoes">
                        <input type="hidden" name="return" value="<?= h($returnUrl); ?>">
                        <input type="hidden" name="processos_action_token" value="<?= h($actionToken); ?>">
                    </form>
                    <form id="<?= h($completePartesFormId); ?>" method="post" action="detalhe_processo.php" hidden>
                        <input type="hidden" name="q" value="<?= h($numero); ?>">
                        <input type="hidden" name="acao" value="consultar_processo">
                        <input type="hidden" name="secao" value="partes">
                        <input type="hidden" name="return" value="<?= h($returnUrl); ?>">
                        <input type="hidden" name="processos_action_token" value="<?= h($actionToken); ?>">
                    </form>

                    <div class="process-detail-layout">
                        <aside class="process-menu" aria-label="Seções do processo">
                            <?php detalhe_menu_link($secaoAtiva, 'geral', 'fa-solid fa-gavel', 'Geral'); ?>
                            <?php detalhe_menu_link($secaoAtiva, 'detalhes', 'fa-regular fa-file-lines', 'Detalhes'); ?>
                            <?php detalhe_menu_link($secaoAtiva, 'movimentacoes', 'fa-solid fa-book-open', 'Movimentações'); ?>
                            <?php detalhe_menu_link($secaoAtiva, 'partes', 'fa-solid fa-users', 'Partes envolvidas'); ?>
                            <?php detalhe_menu_link($secaoAtiva, 'documentos', 'fa-solid fa-folder-open', 'Documentos'); ?>
                        </aside>

                        <section class="process-main">
                            <section class="process-tab-panel" id="geral" data-section-panel="geral" <?= $secaoAtiva === 'geral' ? '' : 'hidden'; ?>>
                            <div class="process-info-grid">
                                <div class="info-block full">
                                    <span>Assunto principal</span>
                                    <strong><?= h($assuntoPrincipalCurto); ?></strong>
                                    <?php if ($assuntoPrincipal !== $assuntoPrincipalCurto || $areaDireito !== '-'): ?>
                                        <details class="subject-details">
                                            <summary>Mostrar detalhes</summary>
                                            <div>
                                                <?php if ($areaDireito !== 'Não informado' && $areaDireito !== '-'): ?>
                                                    <span>Área do direito:</span>
                                                    <p><?= h($areaDireito); ?></p>
                                                <?php endif; ?>
                                                <?php if ($assuntoAmplo !== 'Não informado'): ?>
                                                    <span>Outros assuntos relacionados:</span>
                                                    <p><?= h($assuntoAmplo); ?></p>
                                                <?php endif; ?>
                                                <?php if ($assuntoPrincipal !== 'Não informado' && $assuntoPrincipal !== '-'): ?>
                                                    <span>Assunto completo:</span>
                                                    <p><?= h($assuntoPrincipal); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>
                                <div class="info-block">
                                    <span>Status</span>
                                    <strong><?= h($statusProcesso); ?></strong>
                                </div>
                                <div class="info-block">
                                    <span>Valor da causa</span>
                                    <strong><?= h($valorCausa); ?></strong>
                                </div>
                                <div class="info-block">
                                    <span>Início processo</span>
                                    <strong><?= h($dataInicio); ?></strong>
                                </div>
                                <div class="info-block">
                                    <span>Instância</span>
                                    <strong><?= h($instanciaProcesso); ?></strong>
                                </div>
                                <div class="info-block full">
                                    <span>Área do direito</span>
                                    <strong><?= h($areaDireito); ?></strong>
                                </div>
                                <div class="info-block">
                                    <span>Última atualização <i class="fa-regular fa-circle-question field-help" title="Última data em que o processo foi atualizado." aria-label="Última data em que o processo foi atualizado."></i></span>
                                    <strong><?= h($ultimaAtualizacao); ?></strong>
                                </div>
                                <div class="info-block">
                                    <span>Última verificação <i class="fa-regular fa-circle-question field-help" title="Última data em que foram buscados dados deste processo no tribunal." aria-label="Última data em que foram buscados dados deste processo no tribunal."></i></span>
                                    <strong><?= h($ultimaVerificacao); ?></strong>
                                </div>
                            </div>
                                <div class="overview-bottom">
                                    <section class="overview-card overview-movements">
                                        <div class="overview-card-header">
                                            <h2><i class="fa-solid fa-book-open" aria-hidden="true"></i> Movimentações (<?= h((string) $totalMovimentacoes); ?>)</h2>
                                            <a href="<?= h(detalhe_url('movimentacoes')); ?>" data-section-link="movimentacoes">Ver todas</a>
                                        </div>
                                        <?php if (empty($movimentacoes)): ?>
                                            <p class="dados-empty">Nenhuma movimentação retornada.</p>
                                        <?php else: ?>
                                            <?php foreach (array_slice($movimentacoes, 0, 5) as $movimentacao): ?>
                                                <article class="movement-item compact">
                                                    <time><?= h(detalhe_movement_date($movimentacao)); ?></time>
                                                    <p><?= h(detalhe_movement_text($movimentacao)); ?></p>
                                                </article>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </section>

                                    <section class="overview-card overview-parties">
                                        <div class="overview-card-header">
                                            <h2><i class="fa-solid fa-users" aria-hidden="true"></i> Partes envolvidas (<?= h((string) count($partes)); ?>)</h2>
                                            <a href="<?= h(detalhe_url('partes')); ?>" data-section-link="partes">Ver todas</a>
                                        </div>
                                        <div class="people-list compact">
                                            <?php if (empty($partes)): ?>
                                                <p class="dados-empty">Nenhuma parte retornada.</p>
                                            <?php else: ?>
                                                <?php foreach (array_slice($partes, 0, 4) as $parte): ?>
                                                    <article>
                                                        <i class="fa-regular fa-user" aria-hidden="true"></i>
                                                        <div>
                                                            <strong><?= h(first_value($parte, ['name', 'nome', 'parte'], '-')); ?></strong>
                                                            <span><?= h(first_value($parte, ['specificType', 'pole', 'polarity', 'type', 'tipo', 'papel', 'polo'], 'Parte')); ?></span>
                                                        </div>
                                                    </article>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </section>

                                    <section class="overview-card overview-documents">
                                        <div class="overview-card-header">
                                            <h2><i class="fa-solid fa-folder-open" aria-hidden="true"></i> Documentos principais</h2>
                                            <a href="<?= h(detalhe_url('documentos')); ?>" data-section-link="documentos">Ver todos</a>
                                        </div>
                                        <div class="document-list">
                                            <article>
                                                <i class="fa-regular fa-file-pdf document-icon" aria-hidden="true"></i>
                                                <div>
                                                    <strong>PDF do processo</strong>
                                                    <span>Baixe um PDF que contém os documentos completos do processo.</span>
                                                </div>
                                                <button class="document-download js-paid-confirm"
                                                    type="button"
                                                    data-form-id="<?= h($pdfFormId); ?>"
                                                    data-title="Baixar processo na &iacute;ntegra: <?= h(detalhe_format_cnj($numero)); ?>"
                                                    data-message="Ser&atilde;o gastos"
                                                    data-credit-label="<?= h($creditosPdfProcessoLabel); ?>">
                                                    <i class="fa-solid fa-download" aria-hidden="true"></i>
                                                    Baixar na íntegra
                                                </button>
                                            </article>
                                        </div>
                                    </section>
                                </div>
                            </section>

                                <section class="process-details-section process-tab-panel" id="detalhes" data-section-panel="detalhes" <?= $secaoAtiva === 'detalhes' ? '' : 'hidden'; ?>>
                                    <h2>Detalhes do processo</h2>
                                    <div class="process-details-grid">
                                        <?php detalhe_field_value('Número do processo', detalhe_format_cnj($numero)); ?>
                                        <?php detalhe_field_value('Status', $statusProcesso); ?>
                                        <?php detalhe_field_value('Valor da causa', $valorCausa); ?>
                                        <?php detalhe_field_value('Início processo', $dataInicio); ?>
                                        <?php detalhe_field_value('Instância', $instanciaProcesso); ?>
                                        <?php detalhe_field_value('Tipo do tribunal', detalhe_value($processo, ['courtType', 'type', 'tipo_tribunal'])); ?>
                                        <?php detalhe_field_value('Tipo do processo', detalhe_value($processo, ['lawsuitType', 'processType', 'classe', 'tipo_processo', 'procedimento'])); ?>
                                        <?php detalhe_field_value('Tribunal', detalhe_value($processo, ['courtName', 'tribunal', 'orgao_julgador'])); ?>
                                        <?php detalhe_field_value('Comarca/Distrito', detalhe_value($processo, ['courtDistrict', 'district', 'comarca', 'foro'])); ?>
                                        <?php detalhe_field_value('Estado', detalhe_value($processo, ['state', 'estado', 'uf'])); ?>
                                        <?php detalhe_field_value('Jurisdição', detalhe_value($processo, ['jurisdiction', 'jurisdicao'])); ?>
                                        <?php detalhe_field_value('Juiz', detalhe_value($processo, ['judge', 'juiz', 'magistrado'])); ?>
                                        <?php detalhe_field_value('Classe', detalhe_value($processo, ['className', 'classe', 'procedureName', 'procedimento'])); ?>
                                        <?php detalhe_field_value('Fórum', detalhe_value($processo, ['forum', 'foro', 'courtDistrict'])); ?>
                                        <?php detalhe_field_value('Data de publicação', detalhe_value($processo, ['noticeDate', 'publicationDate', 'data_publicacao'], 'Não informado', 'date')); ?>
                                        <?php detalhe_field_value('Última movimentação', detalhe_value($processo, ['lastMovementDate', 'ultima_movimentacao'], 'Não informado', 'datetime')); ?>
                                        <?php detalhe_field_value('Captura dos dados', detalhe_value($processo, ['captureDate', 'capturedAt', 'data_captura'], 'Não informado', 'datetime')); ?>
                                        <?php detalhe_field_value('Assunto principal', $assuntoPrincipalCurto); ?>
                                        <?php detalhe_field_value('Código assunto', detalhe_value($processo, ['iCNJSubjectNumber', 'iCNJSubjectCode', 'subjectCode', 'codigo_assunto'])); ?>
                                        <?php detalhe_field_value('Procedimento', detalhe_value($processo, ['procedureName', 'procedure', 'procedimento'])); ?>
                                        <?php detalhe_field_value('Área do direito', $areaDireito); ?>
                                        <?php detalhe_field_value('Código área do direito', detalhe_value($processo, ['iBroadCNJSubjectNumber', 'iBroadCNJSubjectCode', 'areaCode', 'codigo_area_direito'])); ?>
                                        <?php detalhe_field_value('Número de partes', detalhe_value($processo, ['numberOfParties', 'numero_partes'])); ?>
                                        <?php detalhe_field_value('Número de atualizações', detalhe_value($processo, ['numberOfUpdates', 'numero_atualizacoes'])); ?>
                                        <?php detalhe_field_value('Idade do processo', detalhe_value($processo, ['lawSuitAge', 'lawsuitAge', 'idade_processo'])); ?>
                                        <?php detalhe_field_value('Média de atualizações/mês', detalhe_value($processo, ['averageNumberOfUpdatesPerMonth', 'media_atualizacoes_mes'])); ?>
                                        <?php detalhe_field_value('Última atualização', $ultimaAtualizacao, 'Última data em que o processo foi atualizado.'); ?>
                                        <?php detalhe_field_value('Última verificação', $ultimaVerificacao, 'Última data em que foram buscados dados deste processo no tribunal.'); ?>
                                    </div>

                                    <div class="detail-long-fields">
                                        <div>
                                            <span>Assunto principal</span>
                                            <p><?= h($assuntoPrincipalCurto); ?></p>
                                        </div>
                                        <div class="soft">
                                            <span>Outros assuntos</span>
                                            <p><?= h($assuntoAmplo !== 'Não informado' ? $assuntoAmplo : $areaDireito); ?></p>
                                        </div>
                                    </div>
                                </section>

                            <section class="detail-section process-tab-panel" id="movimentacoes" data-section-panel="movimentacoes" <?= $secaoAtiva === 'movimentacoes' ? '' : 'hidden'; ?>>
                                <div class="detail-section-header">
                                    <h2><i class="fa-solid fa-book-open" aria-hidden="true"></i> Movimentações (<?= h((string) $totalMovimentacoes); ?>)</h2>
                                    <div class="detail-section-actions">
                                        <?php if ($consultaResumoLimitado && $movimentacoesResumoJaCompleto): ?>
                                            <a class="detail-order-btn js-paid-confirm"
                                                href="#"
                                                data-info-only="1"
                                                data-title="Movimentações atualizadas"
                                                data-message="Este resumo já contém todas as movimentações informadas pela API para este processo. Não é necessário fazer uma nova consulta paga.">
                                                Carregar todas
                                            </a>
                                        <?php elseif ($consultaResumoLimitado): ?>
                                            <a class="detail-order-btn js-paid-confirm"
                                                href="#"
                                                data-form-id="<?= h($completeMovFormId); ?>"
                                                data-title="Ver detalhes do processo: <?= h(detalhe_format_cnj($numero)); ?>"
                                                data-message="Ser&atilde;o gastos"
                                                data-credit-label="<?= h($creditosConsultaCompletaLabel); ?>">
                                                Carregar todas
                                            </a>
                                        <?php endif; ?>
                                        <a class="detail-order-btn" href="<?= h(detalhe_ordem_url($ordemMovimentacoes === 'desc' ? 'asc' : 'desc')); ?>">
                                            <?= $ordemMovimentacoes === 'desc' ? 'Mais antigas primeiro' : 'Mais recentes primeiro'; ?>
                                        </a>
                                    </div>
                                </div>
                                <?php if ($movimentacoesStatusMensagem !== ''): ?>
                                    <div class="movement-status-note">
                                        <i class="fa-regular fa-circle-question" aria-hidden="true"></i>
                                        <span><?= h($movimentacoesStatusMensagem); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($movimentacoes)): ?>
                                    <p class="dados-empty">Nenhuma movimentação retornada.</p>
                                <?php else: ?>
                                    <?php foreach ($movimentacoesPagina as $movimentacao): ?>
                                        <article class="movement-item">
                                            <time><?= h(detalhe_movement_date($movimentacao)); ?></time>
                                            <div class="movement-card">
                                                <p><?= h(detalhe_movement_text($movimentacao)); ?></p>
                                                <?php $origemMov = detalhe_movement_source($movimentacao); ?>
                                                <?php if ($origemMov !== ''): ?>
                                                    <small><?= h($origemMov); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                    <?php if ($totalPaginasMovimentacoes > 1): ?>
                                        <nav class="movement-pagination" aria-label="Páginas de movimentações">
                                            <a class="<?= $paginaMovimentacoes <= 1 ? 'disabled' : ''; ?>" href="<?= h(detalhe_movimentacoes_page_url($paginaMovimentacoes - 1)); ?>">
                                                Anterior
                                            </a>
                                            <span>Página <?= h((string) $paginaMovimentacoes); ?> de <?= h((string) $totalPaginasMovimentacoes); ?></span>
                                            <a class="<?= $paginaMovimentacoes >= $totalPaginasMovimentacoes ? 'disabled' : ''; ?>" href="<?= h(detalhe_movimentacoes_page_url($paginaMovimentacoes + 1)); ?>">
                                                Próxima
                                            </a>
                                        </nav>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </section>

                            <section class="detail-section process-tab-panel" id="partes" data-section-panel="partes" <?= $secaoAtiva === 'partes' ? '' : 'hidden'; ?>>
                                <div class="detail-section-header">
                                    <h2><i class="fa-solid fa-users" aria-hidden="true"></i> Partes envolvidas (<?= h((string) $totalPartes); ?>)</h2>
                                    <?php if ($consultaResumoLimitado && $partesResumoJaCompleto): ?>
                                        <a class="detail-order-btn js-paid-confirm"
                                            href="#"
                                            data-info-only="1"
                                            data-title="Partes envolvidas atualizadas"
                                            data-message="Este resumo já contém todas as partes envolvidas informadas pela API para este processo. Não é necessário fazer uma nova consulta paga.">
                                            Atualizar partes
                                        </a>
                                    <?php elseif ($consultaResumoLimitado): ?>
                                        <a class="detail-order-btn js-paid-confirm"
                                            href="#"
                                            data-form-id="<?= h($completePartesFormId); ?>"
                                            data-title="Ver detalhes do processo: <?= h(detalhe_format_cnj($numero)); ?>"
                                            data-message="Ser&atilde;o gastos"
                                            data-credit-label="<?= h($creditosConsultaCompletaLabel); ?>">
                                            Atualizar partes
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="people-list">
                                    <?php if (empty($partes)): ?>
                                        <p class="dados-empty">Nenhuma parte retornada.</p>
                                    <?php else: ?>
                                        <?php foreach (array_slice($partes, 0, 6) as $parte): ?>
                                            <article>
                                                <i class="fa-regular fa-user" aria-hidden="true"></i>
                                                <div>
                                                    <strong><?= h(first_value($parte, ['name', 'nome', 'parte'], '-')); ?></strong>
                                                    <span><?= h(first_value($parte, ['specificType', 'pole', 'polarity', 'type', 'tipo', 'papel', 'polo'], 'Parte')); ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </section>

                                <section class="detail-section process-tab-panel" id="documentos" data-section-panel="documentos" <?= $secaoAtiva === 'documentos' ? '' : 'hidden'; ?>>
                                    <div class="detail-section-header">
                                        <h2><i class="fa-solid fa-folder-open" aria-hidden="true"></i> Documentos</h2>
                                    </div>
                                    <div class="document-list full">
                                        <article>
                                            <i class="fa-regular fa-file-pdf document-icon" aria-hidden="true"></i>
                                            <div>
                                                <strong>PDF do processo</strong>
                                                <span>Baixe um PDF que contém os documentos completos do processo.</span>
                                            </div>
                                            <button class="document-download js-paid-confirm"
                                            type="button"
                                            data-form-id="<?= h($pdfFormId); ?>"
                                            data-title="Baixar processo na &iacute;ntegra: <?= h(detalhe_format_cnj($numero)); ?>"
                                            data-message="Ser&atilde;o gastos"
                                            data-credit-label="<?= h($creditosPdfProcessoLabel); ?>">
                                                <i class="fa-solid fa-download" aria-hidden="true"></i>
                                                Baixar na íntegra
                                            </button>
                                        </article>
                                    </div>
                                </section>

                        </section>
                    </div>
                <?php endif; ?>
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
                <a href="#" id="paidConfirmSubmit">Confirmar consulta</a>
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
            const sectionLinks = document.querySelectorAll('[data-section-link]');
            const sectionPanels = document.querySelectorAll('[data-section-panel]');
            const modal = document.getElementById('paidConfirmModal');
            const title = document.getElementById('paidConfirmTitle');
            const message = document.getElementById('paidConfirmMessage');
            const submit = document.getElementById('paidConfirmSubmit');
            const actionCancel = modal ? modal.querySelector('.paid-confirm-actions [data-paid-cancel]') : null;
            const loadingOverlay = document.getElementById('processosLoadingOverlay');
            const loadingTitle = document.getElementById('processosLoadingTitle');
            const loadingMessage = document.getElementById('processosLoadingMessage');
            const creditosDisponiveis = <?= json_encode((float) $creditos); ?>;
            let pendingForm = null;

            function activateSection(section, url, pushState) {
                const target = document.querySelector('[data-section-panel="' + section + '"]');

                if (!target) {
                    return false;
                }

                sectionPanels.forEach(function (panel) {
                    panel.hidden = panel !== target;
                });

                sectionLinks.forEach(function (link) {
                    const isActive = link.getAttribute('data-section-link') === section;
                    link.classList.toggle('active', isActive);
                    if (isActive) {
                        link.setAttribute('aria-current', 'page');
                    } else {
                        link.removeAttribute('aria-current');
                    }
                });

                if (pushState && url && window.history && window.history.pushState) {
                    window.history.pushState({ section: section }, '', url);
                }

                return true;
            }

            sectionLinks.forEach(function (link) {
                link.addEventListener('click', function (event) {
                    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    const section = link.getAttribute('data-section-link');

                    if (!section) {
                        return;
                    }

                    if (!activateSection(section, link.getAttribute('href'), true)) {
                        return;
                    }

                    event.preventDefault();
                });
            });

            window.addEventListener('popstate', function () {
                const params = new URLSearchParams(window.location.search);
                activateSection(params.get('secao') || 'geral', '', false);
            });

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
                if (actionCancel) {
                    actionCancel.textContent = 'Cancelar';
                }
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
                    const infoOnly = trigger.getAttribute('data-info-only') === '1';
                    title.textContent = infoOnly
                        ? (trigger.getAttribute('data-title') || 'Aviso')
                        : 'Confirmar consulta';
                    const actionText = trigger.getAttribute('data-title') || 'Confirmar consulta';
                    message.textContent = trigger.getAttribute('data-message') || 'Esta ação pode consumir créditos.';
                    const creditLabel = trigger.getAttribute('data-credit-label');
                    const cost = parseCredits(creditLabel);

                    if (!infoOnly && cost > 0 && creditosDisponiveis < cost) {
                        title.textContent = 'Créditos insuficientes';
                        message.innerHTML = insufficientCreditsMessage(cost);
                        pendingForm = null;
                        submit.hidden = true;
                        if (actionCancel) {
                            actionCancel.textContent = 'Cancelar';
                        }
                        modal.hidden = false;
                        return;
                    }

                    if (!infoOnly && creditLabel) {
                        message.innerHTML = escapeHtml(actionText) + '. Serão gastos <strong>' + escapeHtml(creditLabel + ' créditos') + '</strong>.';
                    }
                    pendingForm = trigger.getAttribute('data-form-id')
                        ? document.getElementById(trigger.getAttribute('data-form-id'))
                        : null;
                    submit.setAttribute('href', trigger.getAttribute('href') || '#');
                    submit.hidden = infoOnly;
                    if (actionCancel) {
                        actionCancel.textContent = infoOnly ? 'Entendi' : 'Cancelar';
                    }
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
                    if (actionCancel) {
                        actionCancel.textContent = 'Entendi';
                    }
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

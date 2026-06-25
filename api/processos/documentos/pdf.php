<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../services/ProcessoDocumentoService.php';
require_once __DIR__ . '/../../../helpers/processos_actions.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/pdf_download_token.php';

bidmap_require_login_for_creditos();

function pdf_env_bool(string $key, bool $default = false): bool
{
    $value = bidmap_env($key);

    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function pdf_db(): mysqli
{
    $mysqli = mysqli_init();

    if (!$mysqli instanceof mysqli) {
        throw new RuntimeException('Não foi possível inicializar a conexão com o banco.');
    }

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

function pdf_creditos(): UsuarioCreditoService
{
    static $service = null;

    if ($service instanceof UsuarioCreditoService) {
        return $service;
    }

    if (!pdf_env_bool('DADOS_PESSOAIS_SKIP_CREDITOS', false)) {
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
            return ['saldo_anterior' => $saldo, 'saldo_posterior' => $saldo, 'bypass_creditos' => true];
        }

        public function estornar(int $idUsuario, float $creditos, string $referencia): array
        {
            $saldo = $this->getSaldo($idUsuario);
            return ['saldo_anterior' => $saldo, 'saldo_posterior' => $saldo, 'bypass_creditos' => true];
        }
    };

    return $service;
}

function pdf_return_url(): string
{
    $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $returnUrl = $request['return'] ?? '../../consultar_processos.php';
    $parts = parse_url($returnUrl);

    if (!$parts || isset($parts['host'])) {
        $returnUrl = '../../../consultar_processos.php';
    }

    if ($returnUrl !== '' && $returnUrl[0] !== '/') {
        $returnUrl = '../../../' . ltrim($returnUrl, '/');
    }

    return $returnUrl;
}

function pdf_redirect(string $status, string $message = ''): void
{
    $returnUrl = pdf_return_url();
    $fragment = '';
    $fragmentPosition = strpos($returnUrl, '#');

    if ($fragmentPosition !== false) {
        $fragment = substr($returnUrl, $fragmentPosition);
        $returnUrl = substr($returnUrl, 0, $fragmentPosition);
    }

    $separator = strpos($returnUrl, '?') !== false ? '&' : '?';
    $location = $returnUrl . $separator . http_build_query([
        'pdf_status' => $status,
        'pdf_message' => $message,
    ]) . $fragment;

    header('Location: ' . $location);
    exit;
}

function pdf_redirect_download_remoto(ProcessoDocumentoService $service, int $consultaId): bool
{
    if (!bidmap_pdf_download_enabled() || $consultaId <= 0) {
        return false;
    }

    $consultaRemota = $service->obterConsultaPdfRemota($consultaId, pdf_creditos()->getUsuarioId());

    if (empty($consultaRemota['ok'])) {
        return false;
    }

    $url = bidmap_pdf_download_url($consultaId, (int) bidmap_env('BIDMAP_PDF_DOWNLOAD_TTL_SECONDS', '300'));

    if ($url === '') {
        return false;
    }

    header('Location: ' . $url);
    exit;
}

try {
    $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    $isHistoricoGet = $_SERVER['REQUEST_METHOD'] === 'GET' && ($request['origem'] ?? '') === 'historico';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isHistoricoGet) {
        pdf_redirect('error', 'Use o botão da plataforma para baixar o PDF do processo.');
    }

    if (!processos_validate_action_token($request['processos_action_token'] ?? null)) {
        pdf_redirect('error', 'Ação expirada. Volte para a tela anterior e tente novamente.');
    }

    $numero = $request['numero'] ?? ($request['q'] ?? '');
    $isHistorico = ($request['origem'] ?? '') === 'historico';
    $registrarAcessoCache = !$isHistorico;
    $ignorarCache = ($request['atualizar'] ?? '') === '1';
    $consultaId = (int) ($request['consulta_id'] ?? 0);
    $mysqli = pdf_db();
    $service = new ProcessoDocumentoService($mysqli, null, pdf_creditos());
    $resultado = ($isHistorico && !$ignorarCache && $consultaId > 0)
        ? $service->obterPdfSalvoPorConsulta($consultaId, pdf_creditos()->getUsuarioId())
        : $service->obterOuSolicitarPdf($numero, $registrarAcessoCache, $ignorarCache);

    if (!$resultado['ok']) {
        if ($consultaId > 0 && pdf_redirect_download_remoto($service, $consultaId)) {
            exit;
        }

        pdf_redirect('error', $resultado['message'] ?? 'Não foi possível baixar o PDF.');
    }

    if (empty($resultado['ready'])) {
        pdf_redirect('processing', 'Consultando dados e criando PDF completo. A página será atualizada automaticamente quando o PDF estiver disponível.');
    }

    $filePath = (string) $resultado['file_path'];

    if (!is_file($filePath)) {
        if ($consultaId > 0 && pdf_redirect_download_remoto($service, $consultaId)) {
            exit;
        }

        pdf_redirect('error', 'O PDF salvo não foi encontrado no servidor.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename((string) $resultado['filename']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    @touch($filePath);
    readfile($filePath);
    exit;
} catch (Throwable $exception) {
    pdf_redirect('error', $exception->getMessage());
}


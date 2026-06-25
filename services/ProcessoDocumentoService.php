<?php

require_once __DIR__ . '/DocumentoValidator.php';
require_once __DIR__ . '/../database/consulta_tables.php';
require_once __DIR__ . '/ProcessoRapidoApiService.php';
require_once __DIR__ . '/UsuarioCreditoService.php';
require_once __DIR__ . '/ConsultaJobService.php';
require_once __DIR__ . '/../database/models/TipoPedidoModel.php';
require_once __DIR__ . '/../database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../database/models/ControleCreditoExternoModel.php';
require_once __DIR__ . '/../database/models/ProcessoFilaModel.php';
require_once __DIR__ . '/../database/models/CustoConsultaModel.php';

class ProcessoDocumentoService
{
    private mysqli $mysqli;
    private TipoPedidoModel $tipoPedidoModel;
    private ConsultaExternaModel $consultaExternaModel;
    private PedidoUsuarioModel $pedidoUsuarioModel;
    private ControleCreditoExternoModel $controleCreditoExternoModel;
    private ProcessoFilaModel $processoFilaModel;
    private CustoConsultaModel $custoConsultaModel;
    private ConsultaJobService $consultaJobService;
    private ProcessoRapidoApiService $apiService;
    private UsuarioCreditoService $usuarioCreditoService;
    private string $storageDir;

    public function __construct(
        mysqli $mysqli,
        ?ProcessoRapidoApiService $apiService = null,
        ?UsuarioCreditoService $usuarioCreditoService = null,
        ?string $storageDir = null
    ) {
        $this->mysqli = $mysqli;
        $this->tipoPedidoModel = new TipoPedidoModel($mysqli);
        $this->consultaExternaModel = new ConsultaExternaModel($mysqli);
        $this->pedidoUsuarioModel = new PedidoUsuarioModel($mysqli);
        $this->controleCreditoExternoModel = new ControleCreditoExternoModel($mysqli);
        $this->processoFilaModel = new ProcessoFilaModel($mysqli);
        $this->custoConsultaModel = new CustoConsultaModel($mysqli);
        $this->consultaJobService = new ConsultaJobService($mysqli);
        $this->apiService = $apiService ?? new ProcessoRapidoApiService();
        $this->usuarioCreditoService = $usuarioCreditoService ?? new UsuarioCreditoService();
        $this->storageDir = $this->resolverStorageDir($storageDir ?? bidmap_env('PROCESSO_RAPIDO_STORAGE_DIR', 'storage/processos_pdf'));
    }

    private function resolverStorageDir(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return dirname(__DIR__) . '/storage/processos_pdf';
        }

        if (preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $path)) {
            return $path;
        }

        $appRoot = dirname(__DIR__);
        $relativePath = ltrim($path, '/\\');
        $primary = $appRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (is_dir($primary)) {
            return $primary;
        }

        $shared = dirname($appRoot) . DIRECTORY_SEPARATOR . $relativePath;

        if (is_dir($shared)) {
            return $shared;
        }

        return $primary;
    }

    public function obterOuSolicitarPdf(string $numeroProcesso, bool $registrarAcessoCache = true, bool $ignorarCache = false): array
    {
        $numeroOriginal = trim($numeroProcesso);
        $numeroNormalizado = DocumentoValidator::onlyDigits($numeroOriginal);

        if (strlen($numeroNormalizado) !== 20) {
            return $this->erro('Número CNJ inválido.');
        }

        $modalidade = 'pdf_processo';
        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade($modalidade);

        if (!$tipoPedido) {
            return $this->erro('Modalidade de PDF do processo não configurada.');
        }

        $fornecedor = $tipoPedido['fornecedor_padrao'] ?: 'processorapido';
        $cache = $this->buscarPdfValido($modalidade, $numeroNormalizado, $fornecedor);

        if ($cache && !$ignorarCache && $this->pdfDisponivelParaCliente($cache)) {
            $this->ajustarValidadeCachePdf((int) $cache['id_consulta']);
            if ($this->arquivoLocalExiste($cache['link_pdf_cliente'] ?? '')) {
                $this->removerCapaSePossivel((string) $cache['link_pdf_cliente']);
            }
            $cacheAtualizado = $this->consultaExternaModel->buscarPorId((int) $cache['id_consulta']);
            if ($registrarAcessoCache) {
                $this->registrarAcessoCachePdf($tipoPedido, $modalidade, $numeroOriginal, $numeroNormalizado, $fornecedor, $cacheAtualizado ?: $cache);
            }
            return $this->pdfPronto($cacheAtualizado ?: $cache);
        }

        $pendente = $this->buscarPdfPendente($modalidade, $numeroNormalizado, $fornecedor);

        if ($pendente) {
            return $this->pendentePorConsulta($pendente);
        }

        return $this->criarRequisicao($tipoPedido, $modalidade, $numeroOriginal, $numeroNormalizado, $fornecedor, $ignorarCache);
    }

    public function verificarPendentePorConsulta(int $consultaId): array
    {
        if ($consultaId <= 0) {
            return $this->erro('Consulta de PDF inválida.');
        }

        $consulta = $this->consultaExternaModel->buscarPorId($consultaId);

        if (!$consulta || ($consulta['modalidade_pedido'] ?? '') !== 'pdf_processo') {
            return $this->erro('Consulta de PDF não encontrada.');
        }

        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade('pdf_processo');

        if (!$tipoPedido) {
            return $this->erro('Modalidade de PDF do processo não configurada.');
        }

        $fornecedor = $tipoPedido['fornecedor_padrao'] ?: 'processorapido';

        if (($consulta['status_resultado'] ?? '') === 'sucesso') {
            return $this->pdfDisponivelParaCliente($consulta)
                ? $this->pdfPronto($consulta)
                : $this->erro('PDF marcado como disponível, mas o arquivo local não foi encontrado.');
        }

        if (($consulta['status_resultado'] ?? '') !== 'pendente') {
            return $this->erro((string) ($consulta['mensagem_resultado'] ?? 'Consulta de PDF não está pendente.'));
        }

        return $this->tentarFinalizarRequisicao($consulta, $tipoPedido, $fornecedor);
    }

    public function obterPdfSalvoPorConsulta(int $consultaId, ?int $idUsuario = null): array
    {
        if ($consultaId <= 0) {
            return $this->erro('Consulta de PDF invalida.');
        }

        $consulta = $this->consultaExternaModel->buscarPorId($consultaId);

        if (!$consulta || ($consulta['modalidade_pedido'] ?? '') !== 'pdf_processo') {
            return $this->erro('Consulta de PDF nao encontrada.');
        }

        if ($idUsuario !== null && !$this->usuarioPodeAcessarConsulta($idUsuario, $consultaId)) {
            return $this->erro('Consulta de PDF nao encontrada para este usuario.');
        }

        if (($consulta['status_resultado'] ?? '') !== 'sucesso') {
            return $this->erro((string) ($consulta['mensagem_resultado'] ?? 'PDF salvo ainda nao esta disponivel.'));
        }

        if (!$this->pdfDisponivelParaCliente($consulta)) {
            return $this->erro('A versão salva do PDF não foi encontrada no servidor. Atualize a consulta para baixar uma nova versão.');
        }

        $this->ajustarValidadeCachePdf($consultaId);
        if ($this->arquivoLocalExiste($consulta['link_pdf_cliente'] ?? '')) {
            $this->removerCapaSePossivel((string) $consulta['link_pdf_cliente']);
        }

        $consultaAtualizada = $this->consultaExternaModel->buscarPorId($consultaId);
        return $this->pdfPronto($consultaAtualizada ?: $consulta);
    }

    public function obterConsultaPdfRemota(int $consultaId, ?int $idUsuario = null): array
    {
        if ($consultaId <= 0) {
            return $this->erro('Consulta de PDF invalida.');
        }

        $consulta = $this->consultaExternaModel->buscarPorId($consultaId);

        if (!$consulta || ($consulta['modalidade_pedido'] ?? '') !== 'pdf_processo') {
            return $this->erro('Consulta de PDF nao encontrada.');
        }

        if ($idUsuario !== null && !$this->usuarioPodeAcessarConsulta($idUsuario, $consultaId)) {
            return $this->erro('Consulta de PDF nao encontrada para este usuario.');
        }

        if (($consulta['status_resultado'] ?? '') !== 'sucesso') {
            return $this->erro((string) ($consulta['mensagem_resultado'] ?? 'PDF salvo ainda nao esta disponivel.'));
        }

        if (trim((string) ($consulta['link_pdf_cliente'] ?? '')) === '') {
            return $this->erro('Caminho local do PDF nao foi registrado.');
        }

        return [
            'ok' => true,
            'consulta_id' => $consultaId,
        ];
    }

    private function criarRequisicao(
        array $tipoPedido,
        string $modalidade,
        string $numeroOriginal,
        string $numeroNormalizado,
        string $fornecedor,
        bool $forcarNovaConsulta = false
    ): array {
        $custoConfig = $this->custoConsultaModel->buscarPorChave('pdf_processo');
        $creditosUsuario = (float) ($custoConfig['creditos_usuario'] ?? $tipoPedido['creditos_usuario']);
        $idUsuario = $this->usuarioCreditoService->getUsuarioId();

        $pedidoCobradoRecente = $this->pedidoUsuarioModel->buscarCobradoRecentePorUsuarioEntrada(
            $idUsuario,
            $modalidade,
            $numeroNormalizado,
            max(60, (int) bidmap_env('PROCESSO_RAPIDO_PDF_ANTI_DUPLICATE_SECONDS', '600'))
        );

        if ($pedidoCobradoRecente) {
            $consultaIdRecente = !empty($pedidoCobradoRecente['id_consulta']) ? (int) $pedidoCobradoRecente['id_consulta'] : null;

            if (($pedidoCobradoRecente['status_resultado'] ?? '') === 'sucesso' && $consultaIdRecente) {
                return $this->obterPdfSalvoPorConsulta($consultaIdRecente, $idUsuario);
            }

            return $this->pendente(
                0,
                $this->mensagemProcessamentoPdf(),
                $consultaIdRecente,
                (int) $pedidoCobradoRecente['id_consulta_usuario']
            );
        }

        $saldoAnterior = $this->usuarioCreditoService->getSaldo($idUsuario);

        $pedidoPendenteEntrada = $this->pedidoUsuarioModel->buscarPendentePorUsuarioEntrada(
            $idUsuario,
            $modalidade,
            $numeroNormalizado
        );

        if ($pedidoPendenteEntrada) {
            return $this->pendente(
                0,
                (string) ($pedidoPendenteEntrada['mensagem_resultado'] ?? 'PDF do processo aguardando processamento.'),
                !empty($pedidoPendenteEntrada['id_consulta']) ? (int) $pedidoPendenteEntrada['id_consulta'] : null
            );
        }

        if ($saldoAnterior < $creditosUsuario) {
            return $this->erro('Créditos insuficientes.');
        }

        $descricaoCredito = $this->descricaoCreditoUsuario($modalidade, $numeroOriginal);
        $debito = $this->usuarioCreditoService->debitar($idUsuario, $creditosUsuario, $descricaoCredito);
        $saldoDepois = (float) ($debito['saldo_posterior'] ?? ($saldoAnterior - $creditosUsuario));
        $saldoAntes = (float) ($debito['saldo_anterior'] ?? $saldoAnterior);

        $pedidoId = $this->pedidoUsuarioModel->criarPendente(
            $idUsuario,
            (int) $tipoPedido['id_tipo_pedido'],
            $modalidade,
            $numeroOriginal,
            $numeroNormalizado,
            $creditosUsuario,
            $saldoAntes,
            $saldoDepois
        );
        $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $debito);

        $mensagemFila = $this->mensagemProcessamentoPdf();
        $this->pedidoUsuarioModel->marcarPendenteProcessamento($pedidoId, null, $fornecedor, $mensagemFila);
        $this->enfileirarProcessamentoPdf(null, 0, $fornecedor, $mensagemFila, $pedidoId, $numeroOriginal, $numeroNormalizado, $forcarNovaConsulta);

        return $this->pendente(0, $mensagemFila, null, $pedidoId);
    }

    private function tentarFinalizarRequisicao(array $consulta, array $tipoPedido, string $fornecedor): array
    {
        $dados = $this->decodeJson($consulta['dados_json'] ?? null);
        $reqId = (int) ($dados['req_id'] ?? $dados['requisicao']['id'] ?? 0);

        if ($reqId <= 0) {
            $criacao = $this->criarRequisicaoExternaPdf($consulta);

            if (($criacao['ok'] ?? false) === false) {
                return $this->falharPdfComEstorno($consulta, (string) ($criacao['message'] ?? 'Nao foi possivel criar a requisicao externa.'), $criacao);
            }

            $reqId = (int) ($criacao['req_id'] ?? 0);
            if ($reqId <= 0) {
                return $this->falharPdfComEstorno($consulta, 'O Processo Rapido nao retornou ID da requisicao.', $criacao);
            }
        }

        $resultado = $this->apiService->obterRequisicao($reqId);

        if (!$resultado['ok']) {
            return $this->falharPdfComEstorno($consulta, $resultado['message'], $resultado);
        }

        $requisicao = $resultado['raw']['requisicao'] ?? null;

        if (!is_array($requisicao)) {
            return $this->falharPdfComEstorno($consulta, 'O Processo Rapido nao retornou a requisicao do PDF.', $resultado);
        }

        $pdfUrl = $requisicao['processo']['pdf'] ?? null;
        $statusDescricao = (string) ($requisicao['status_descricao'] ?? 'Em processamento');

        if (!$pdfUrl) {
            $this->consultaExternaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], $statusDescricao, [
                'req_id' => $reqId,
                'requisicao' => $requisicao,
            ]);
            $this->enfileirarProcessamentoPdf((int) $consulta['id_consulta'], $reqId, $fornecedor, $statusDescricao);

            return $this->pendente($reqId, $this->mensagemProcessamentoPdf());
        }

        $download = $this->baixarPdfLocal((string) $consulta['entrada_normalizada'], (string) $pdfUrl);

        if (!$download['ok']) {
            return $this->falharPdfComEstorno($consulta, $download['message'], $download);
        }

        $localPath = (string) $download['file_path'];
        $creditosExternos = $this->custoExternoPdf($tipoPedido, $requisicao);
        $validade = $this->dataValidadeCachePdf();
        $linkExpiraEm = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $this->marcarPdfSucesso(
            (int) $consulta['id_consulta'],
            $resultado['raw'],
            $localPath,
            (string) $pdfUrl,
            $linkExpiraEm,
            $creditosExternos,
            $validade
        );

        if ($creditosExternos > 0) {
            $this->controleCreditoExternoModel->registrarDebito(
                $fornecedor,
                (int) $consulta['id_consulta'],
                $creditosExternos,
                'Consumo externo em pdf_processo'
            );
        }

        $pedido = $this->pedidoUsuarioModel->buscarPendentePorConsulta((int) $consulta['id_consulta']);
        if ($pedido) {
            $this->pedidoUsuarioModel->marcarSucessoWeb(
                (int) $pedido['id_consulta_usuario'],
                (int) $consulta['id_consulta'],
                $fornecedor,
                $creditosExternos,
                'PDF do processo baixado e salvo no cache interno.'
            );
        }

        $consultaAtualizada = $this->consultaExternaModel->buscarPorId((int) $consulta['id_consulta']);
        return $consultaAtualizada ? $this->pdfPronto($consultaAtualizada) : $this->erro('PDF salvo, mas não foi possível reabrir o registro.');
    }

    private function processarRequisicaoRetornada(
        array $consulta,
        array $tipoPedido,
        string $fornecedor,
        array $requisicao,
        array $raw
    ): array {
        $reqId = (int) ($requisicao['id'] ?? 0);
        $pdfUrl = $requisicao['processo']['pdf'] ?? null;
        $statusDescricao = (string) ($requisicao['status_descricao'] ?? 'Em processamento');

        if (!$pdfUrl) {
            $this->consultaExternaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], $statusDescricao, [
                'req_id' => $reqId,
                'requisicao' => $requisicao,
            ]);
            $this->enfileirarProcessamentoPdf((int) $consulta['id_consulta'], $reqId, $fornecedor, $statusDescricao);

            return $this->pendente($reqId, $this->mensagemProcessamentoPdf());
        }

        $download = $this->baixarPdfLocal((string) $consulta['entrada_normalizada'], (string) $pdfUrl);

        if (!$download['ok']) {
            return $this->falharPdfComEstorno($consulta, $download['message'], $download);
        }

        $localPath = (string) $download['file_path'];
        $creditosExternos = $this->custoExternoPdf($tipoPedido, $requisicao);
        $validade = $this->dataValidadeCachePdf();
        $linkExpiraEm = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $this->marcarPdfSucesso(
            (int) $consulta['id_consulta'],
            $raw,
            $localPath,
            (string) $pdfUrl,
            $linkExpiraEm,
            $creditosExternos,
            $validade
        );

        if ($creditosExternos > 0) {
            $this->controleCreditoExternoModel->registrarDebito(
                $fornecedor,
                (int) $consulta['id_consulta'],
                $creditosExternos,
                'Consumo externo em pdf_processo'
            );
        }

        $pedido = $this->pedidoUsuarioModel->buscarPendentePorConsulta((int) $consulta['id_consulta']);
        if ($pedido) {
            $this->pedidoUsuarioModel->marcarSucessoWeb(
                (int) $pedido['id_consulta_usuario'],
                (int) $consulta['id_consulta'],
                $fornecedor,
                $creditosExternos,
                'PDF do processo baixado e salvo no cache interno.'
            );
        }

        $consultaAtualizada = $this->consultaExternaModel->buscarPorId((int) $consulta['id_consulta']);
        return $consultaAtualizada ? $this->pdfPronto($consultaAtualizada) : $this->erro('PDF salvo, mas não foi possível reabrir o registro.');
    }

    private function buscarRequisicaoExistente(string $numeroNormalizado): ?array
    {
        $resultado = $this->apiService->pesquisarRequisicao($this->formatarCnj($numeroNormalizado));

        if (!$resultado['ok']) {
            return null;
        }

        $requisicoes = $resultado['raw']['requisicoes'] ?? [];

        if (!is_array($requisicoes)) {
            return null;
        }

        foreach ($requisicoes as $requisicao) {
            if (!is_array($requisicao)) {
                continue;
            }

            $numero = DocumentoValidator::onlyDigits((string) ($requisicao['processo']['numero'] ?? ''));

            if ($numero === $numeroNormalizado) {
                return [
                    'resultado' => $resultado,
                    'requisicao' => $requisicao,
                ];
            }
        }

        return null;
    }

    private function buscarPdfValido(string $modalidade, string $entradaNormalizada, string $fornecedor): ?array
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT *
                FROM {$consultasExternas}
                WHERE modalidade_pedido = ?
                  AND entrada_normalizada = ?
                  AND fornecedor = ?
                  AND status_resultado = 'sucesso'
                  AND link_pdf_cliente IS NOT NULL
                  AND (data_validade IS NULL OR data_validade >= NOW())
                ORDER BY data_resposta DESC, id_consulta DESC
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('sss', $modalidade, $entradaNormalizada, $fornecedor);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    private function buscarPdfPendente(string $modalidade, string $entradaNormalizada, string $fornecedor): ?array
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT *
                FROM {$consultasExternas}
                WHERE modalidade_pedido = ?
                  AND entrada_normalizada = ?
                  AND fornecedor = ?
                  AND status_resultado = 'pendente'
                ORDER BY data_consulta DESC, id_consulta DESC
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('sss', $modalidade, $entradaNormalizada, $fornecedor);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    private function marcarPdfSucesso(
        int $consultaId,
        array $dados,
        string $localPath,
        string $pdfUrl,
        string $linkExpiraEm,
        float $creditosExternos,
        string $dataValidade
    ): void {
        $status = 'sucesso';
        $mensagem = 'PDF do processo baixado com sucesso';
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);

        $consultasExternas = consulta_table('consultas_externas');
        $sql = "UPDATE {$consultasExternas}
                SET data_resposta = NOW(),
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    dados_json = ?,
                    data_validade = ?,
                    creditos_externo_consumido = ?,
                    link_original_pdf = ?,
                    link_pdf_cliente = ?,
                    link_expira_em = ?
                WHERE id_consulta = ?";

        $localPathCliente = $this->caminhoPdfParaBanco($localPath);
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('ssssdsssi', $status, $mensagem, $json, $dataValidade, $creditosExternos, $pdfUrl, $localPathCliente, $linkExpiraEm, $consultaId);
        $stmt->execute();
        $stmt->close();
    }

    private function salvarPdfLocal(string $numeroNormalizado, string $content): string
    {
        $path = $this->novoCaminhoPdfLocal($numeroNormalizado);
        file_put_contents($path, $content);
        $this->removerCapaSePossivel($path);

        return $path;
    }

    private function baixarPdfLocal(string $numeroNormalizado, string $pdfUrl): array
    {
        $path = $this->novoCaminhoPdfLocal($numeroNormalizado);
        $download = $this->apiService->baixarArquivoParaArquivo($pdfUrl, $path);

        if (empty($download['ok'])) {
            @unlink($path);
            return $download;
        }

        $this->removerCapaSePossivel($path);
        $download['file_path'] = $path;

        return $download;
    }

    private function novoCaminhoPdfLocal(string $numeroNormalizado): string
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }

        $filename = $numeroNormalizado . '_' . date('Ymd_His') . '.pdf';

        return rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }

    private function removerCapaSePossivel(string $path): void
    {
        $path = $this->resolverCaminhoPdfLocal($path);

        if (!$this->envBool('PROCESSO_RAPIDO_REMOVER_CAPA_PDF', true)) {
            return;
        }

        if (!$this->arquivoLocalExiste($path)) {
            return;
        }

        $markerPath = $path . '.sem_capa';

        if (is_file($markerPath)) {
            return;
        }

        $tempPath = $path . '.tmp.pdf';
        @unlink($tempPath);

        $ok = $this->removerCapaComQpdf($path, $tempPath)
            || $this->removerCapaComGhostscript($path, $tempPath);

        if (!$ok || !$this->arquivoLocalExiste($tempPath)) {
            @unlink($tempPath);
            return;
        }

        if (@copy($tempPath, $path)) {
            @unlink($tempPath);
            @file_put_contents($markerPath, date('c'));
            return;
        }

        @unlink($tempPath);
    }

    private function removerCapaComQpdf(string $inputPath, string $outputPath): bool
    {
        $qpdf = $this->localizarExecutavel('PROCESSO_RAPIDO_QPDF_PATH', ['qpdf']);

        if (!$qpdf) {
            return false;
        }

        return $this->executarComando([
            $qpdf,
            $inputPath,
            '--pages',
            $inputPath,
            '2-z',
            '--',
            $outputPath,
        ]);
    }

    private function removerCapaComGhostscript(string $inputPath, string $outputPath): bool
    {
        $gs = $this->localizarExecutavel('PROCESSO_RAPIDO_GHOSTSCRIPT_PATH', ['gswin64c', 'gswin32c', 'gs']);

        if (!$gs) {
            return false;
        }

        return $this->executarComando([
            $gs,
            '-sDEVICE=pdfwrite',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-dFirstPage=2',
            '-sOutputFile=' . $outputPath,
            $inputPath,
        ]);
    }

    private function localizarExecutavel(string $envKey, array $candidates): ?string
    {
        $configured = trim((string) bidmap_env($envKey, ''));

        if ($configured !== '' && (is_file($configured) || $this->executavelDisponivel($configured))) {
            return $configured;
        }

        foreach ($candidates as $candidate) {
            if ($this->executavelDisponivel($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function executavelDisponivel(string $command): bool
    {
        $checkCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'where ' . escapeshellarg($command) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1';

        exec($checkCommand, $output, $code);
        return $code === 0;
    }

    private function executarComando(array $parts): bool
    {
        $command = implode(' ', array_map('escapeshellarg', $parts));
        exec($command, $output, $code);

        return $code === 0;
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $value = bidmap_env($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function dataValidadeCachePdf(): string
    {
        $dias = (int) bidmap_env('PROCESSO_RAPIDO_PDF_RETENTION_DAYS', '30');
        $dias = max(1, $dias);

        return date('Y-m-d H:i:s', strtotime('+' . $dias . ' days'));
    }

    private function custoExternoPdf(array $tipoPedido, array $requisicao): float
    {
        $config = $this->custoConsultaModel->buscarPorChave('pdf_processo');

        if ($config) {
            return (float) $config['custo_externo'];
        }

        $env = trim((string) bidmap_env('PROCESSO_RAPIDO_CUSTO_PDF', ''));

        if ($env !== '') {
            return (float) str_replace(',', '.', $env);
        }

        return (float) ($tipoPedido['creditos_externo'] ?? $requisicao['custo'] ?? 0);
    }

    private function ajustarValidadeCachePdf(int $consultaId): void
    {
        $dias = max(1, (int) bidmap_env('PROCESSO_RAPIDO_PDF_RETENTION_DAYS', '30'));
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "UPDATE {$consultasExternas}
                SET data_validade = DATE_ADD(COALESCE(data_resposta, data_consulta, NOW()), INTERVAL ? DAY)
                WHERE id_consulta = ?
                  AND modalidade_pedido = 'pdf_processo'
                  AND status_resultado = 'sucesso'
                  AND (
                      data_validade IS NULL
                      OR data_validade > DATE_ADD(COALESCE(data_resposta, data_consulta, NOW()), INTERVAL ? DAY)
                  )";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('iii', $dias, $consultaId, $dias);
        $stmt->execute();
        $stmt->close();
    }

    private function registrarAcessoCachePdf(
        array $tipoPedido,
        string $modalidade,
        string $numeroOriginal,
        string $numeroNormalizado,
        string $fornecedor,
        array $consulta
    ): void {
        $idUsuario = $this->usuarioCreditoService->getUsuarioId();
        $saldoAtual = $this->saldoAtualSeguro($idUsuario);
        $pedidoExistente = $this->pedidoUsuarioModel->buscarSucessoPorConsultaUsuario($idUsuario, (int) $consulta['id_consulta']);

        if ($pedidoExistente) {
            return;
        }

        $pedidoId = $this->pedidoUsuarioModel->criarPendente(
            $idUsuario,
            (int) $tipoPedido['id_tipo_pedido'],
            $modalidade,
            $numeroOriginal,
            $numeroNormalizado,
            0.0,
            $saldoAtual,
            $saldoAtual
        );

        $this->pedidoUsuarioModel->marcarSucessoCache($pedidoId, (int) $consulta['id_consulta'], $fornecedor);
    }

    private function saldoAtualSeguro(int $idUsuario): ?float
    {
        try {
            return $this->usuarioCreditoService->getSaldo($idUsuario);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function arquivoLocalExiste(string $path): bool
    {
        $path = $this->resolverCaminhoPdfLocal($path);

        return $path !== '' && is_file($path) && filesize($path) > 0;
    }

    private function pdfDisponivelParaCliente(array $consulta): bool
    {
        return $this->arquivoLocalExiste((string) ($consulta['link_pdf_cliente'] ?? ''));
    }

    private function caminhoPdfParaBanco(string $path): string
    {
        $resolvedStorage = realpath($this->storageDir) ?: $this->storageDir;
        $resolvedStorage = rtrim(str_replace('\\', '/', $resolvedStorage), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($resolvedStorage !== '' && str_starts_with($normalizedPath, $resolvedStorage . '/')) {
            return 'storage/processos_pdf/' . ltrim(substr($normalizedPath, strlen($resolvedStorage) + 1), '/');
        }

        $basename = basename($normalizedPath);

        if ($basename !== '' && preg_match('/\.pdf$/i', $basename)) {
            return 'storage/processos_pdf/' . $basename;
        }

        return $path;
    }

    private function resolverCaminhoPdfLocal(string $path): string
    {
        $path = trim($path);

        if ($path === '' || is_file($path)) {
            return $path;
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

            foreach ($this->storageDirCandidates() as $storageDir) {
                $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $basename = basename($normalized);

        if ($basename !== '' && preg_match('/\.pdf$/i', $basename)) {
            foreach ($this->storageDirCandidates() as $storageDir) {
                $candidate = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . $basename;

                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return $path;
    }

    private function storageDirCandidates(): array
    {
        $appRoot = dirname(__DIR__);
        $relativeStorage = 'storage' . DIRECTORY_SEPARATOR . 'processos_pdf';
        $dirs = [
            $this->storageDir,
            $appRoot . DIRECTORY_SEPARATOR . $relativeStorage,
            dirname($appRoot) . DIRECTORY_SEPARATOR . $relativeStorage,
        ];

        return array_values(array_unique(array_map(static function (string $dir): string {
            return rtrim($dir, '/\\');
        }, $dirs)));
    }

    private function usuarioPodeAcessarConsulta(int $idUsuario, int $consultaId): bool
    {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "SELECT 1
                FROM {$pedidosUsuarios}
                WHERE id_usuario = ?
                  AND id_consulta = ?
                  AND modalidade_pedido = 'pdf_processo'
                  AND status_resultado = 'sucesso'
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('ii', $idUsuario, $consultaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $allowed = (bool) $result->fetch_row();
        $stmt->close();

        return $allowed;
    }

    private function pdfPronto(array $consulta): array
    {
        $filePath = $this->resolverCaminhoPdfLocal((string) $consulta['link_pdf_cliente']);

        return [
            'ok' => true,
            'ready' => true,
            'consulta_id' => (int) $consulta['id_consulta'],
            'file_path' => $filePath,
            'filename' => $this->formatarCnj((string) $consulta['entrada_normalizada']) . '.pdf',
        ];
    }

    private function pendente(int $reqId, string $mensagem = '', ?int $consultaId = null, ?int $pedidoId = null): array
    {
        if ($mensagem === '') {
            $mensagem = $this->mensagemProcessamentoPdf();
        }

        return [
            'ok' => true,
            'ready' => false,
            'pending' => true,
            'consulta_id' => $consultaId,
            'pedido_id' => $pedidoId,
            'req_id' => $reqId,
            'message' => $mensagem,
        ];
    }

    private function mensagemProcessamentoPdf(): string
    {
        return 'Consultando dados e criando PDF completo. A página será atualizada automaticamente quando o PDF estiver disponível.';
    }

    private function pendentePorConsulta(array $consulta): array
    {
        $dados = $this->decodeJson($consulta['dados_json'] ?? null);
        $reqId = (int) ($dados['req_id'] ?? $dados['requisicao']['id'] ?? 0);

        return $this->pendente(
            $reqId,
            $this->mensagemProcessamentoPdf(),
            (int) $consulta['id_consulta']
        );
    }

    private function formatarCnj(string $digits): string
    {
        $digits = DocumentoValidator::onlyDigits($digits);

        if (strlen($digits) !== 20) {
            return $digits;
        }

        return substr($digits, 0, 7) . '-' . substr($digits, 7, 2) . '.' . substr($digits, 9, 4) . '.' .
            substr($digits, 13, 1) . '.' . substr($digits, 14, 2) . '.' . substr($digits, 16, 4);
    }

    private function decodeJson(?string $json): array
    {
        $decoded = $json ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function falharPdfComEstorno(array $consulta, string $mensagem, ?array $dadosErro = null): array
    {
        $consultaId = (int) ($consulta['id_consulta'] ?? 0);

        if ($consultaId > 0) {
            $this->consultaExternaModel->marcarErro($consultaId, $mensagem, $dadosErro);
        }

        $pedido = $consultaId > 0 ? $this->pedidoUsuarioModel->buscarPendentePorConsulta($consultaId) : null;

        if (!$pedido) {
            return $this->erro($mensagem);
        }

        $pedidoId = (int) $pedido['id_consulta_usuario'];

        if (($pedido['status_resultado'] ?? '') === 'estornado' || !empty($pedido['credito_estorno_transacao_id'])) {
            $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, 'estornado');
            return $this->erro($mensagem);
        }

        $creditosConsumidos = (float) ($pedido['creditos_usuario_consumidos'] ?? 0);
        $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, $creditosConsumidos > 0 ? 'estornado' : 'erro');

        if ($creditosConsumidos <= 0) {
            return $this->erro($mensagem);
        }

        $estorno = $this->usuarioCreditoService->estornar(
            (int) $pedido['id_usuario'],
            $creditosConsumidos,
            $this->descricaoCreditoUsuario(
                (string) ($pedido['modalidade_pedido'] ?? 'pdf_processo'),
                (string) ($pedido['entrada_original'] ?? $pedido['entrada_normalizada'] ?? '')
            )
        );
        $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $estorno, true);

        return $this->erro($mensagem);
    }

    private function criarRequisicaoExternaPdf(array $consulta): array
    {
        $numeroNormalizado = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));
        $criacao = $this->apiService->criarRequisicao($this->formatarCnj($numeroNormalizado));

        if (!$criacao['ok']) {
            $existente = $this->buscarRequisicaoExistente($numeroNormalizado);

            if ($existente) {
                $requisicao = $existente['requisicao'];
                $reqId = (int) ($requisicao['id'] ?? 0);

                if ($reqId > 0) {
                    $this->consultaExternaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], 'Requisicao encontrada no historico do Processo Rapido.', [
                        'req_id' => $reqId,
                        'requisicao' => $requisicao,
                        'searchResponse' => $existente['resultado']['raw'] ?? null,
                    ]);

                    return [
                        'ok' => true,
                        'req_id' => $reqId,
                        'raw' => $existente['resultado']['raw'] ?? [],
                        'message' => 'Requisicao encontrada no historico do Processo Rapido.',
                    ];
                }
            }

            return $criacao;
        }

        $requisicao = $criacao['raw']['requisicoes'][0] ?? null;
        $reqId = is_array($requisicao) ? (int) ($requisicao['id'] ?? 0) : 0;

        if ($reqId > 0) {
            $this->consultaExternaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], $this->mensagemProcessamentoPdf(), [
                'req_id' => $reqId,
                'createResponse' => $criacao['raw'],
            ]);
        }

        return [
            'ok' => $reqId > 0,
            'req_id' => $reqId,
            'raw' => $criacao['raw'] ?? null,
            'message' => $reqId > 0
                ? $this->mensagemProcessamentoPdf()
                : 'O Processo Rapido nao retornou ID da requisicao.',
        ];
    }

    private function descricaoCreditoUsuario(string $modalidade, string $numeroOriginal): string
    {
        $label = $modalidade === 'pdf_processo'
            ? 'PDF do processo'
            : 'Consulta de processo';

        return sprintf(
            '%s em %s - CNJ %s',
            $label,
            date('d/m/Y H:i'),
            trim($numeroOriginal)
        );
    }

    private function enfileirarProcessamentoPdf(
        ?int $consultaId,
        int $reqId,
        string $fornecedor,
        string $mensagem,
        ?int $pedidoId = null,
        string $numeroOriginal = '',
        string $numeroNormalizado = '',
        bool $forcarNovaConsulta = false
    ): void
    {
        $pedido = $pedidoId ? $this->pedidoUsuarioModel->buscarPorId($pedidoId) : null;
        if (!$pedido && $consultaId) {
            $pedido = $this->pedidoUsuarioModel->buscarPendentePorConsulta($consultaId);
        }

        $jobPedidoId = $pedido ? (int) $pedido['id_consulta_usuario'] : null;
        $payload = [
            'req_id' => $reqId,
            'fornecedor' => $fornecedor,
            'modalidade_pedido' => 'pdf_processo',
            'entrada_original' => $numeroOriginal !== '' ? $numeroOriginal : (string) ($pedido['entrada_original'] ?? ''),
            'entrada_normalizada' => $numeroNormalizado !== '' ? $numeroNormalizado : (string) ($pedido['entrada_normalizada'] ?? ''),
            'mensagem' => $mensagem,
            'forcar_nova_consulta' => $forcarNovaConsulta,
            'ignorar_cache' => $forcarNovaConsulta,
        ];

        try {
            if ($this->legacyMysqlQueueEnabled()) {
                $this->processoFilaModel->enfileirar(
                    'pdf_processo',
                    $consultaId,
                    $jobPedidoId,
                    $payload
                );
            }
        } catch (Throwable $exception) {
            // A fila não deve impedir o fluxo principal de criação/consulta do PDF.
        }
        try {
            $this->consultaJobService->registrar('pdf_processo', $consultaId, $jobPedidoId, $payload);
        } catch (Throwable $exception) {
            // O job Redis futuro tambem nao deve impedir o fluxo principal do PDF.
        }
    }

    private function erro(string $mensagem): array
    {
        return [
            'ok' => false,
            'message' => $mensagem,
        ];
    }

    private function legacyMysqlQueueEnabled(): bool
    {
        $value = function_exists('bidmap_env')
            ? bidmap_env('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED', 'false')
            : (getenv('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED') ?: 'false');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

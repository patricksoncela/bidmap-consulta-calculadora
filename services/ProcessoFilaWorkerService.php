<?php

require_once __DIR__ . '/ConsultaProcessosApiService.php';
require_once __DIR__ . '/ProcessoDocumentoService.php';
require_once __DIR__ . '/DocumentoService.php';
require_once __DIR__ . '/DocumentoValidator.php';
require_once __DIR__ . '/../database/models/ProcessoFilaModel.php';
require_once __DIR__ . '/../database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../database/models/TipoPedidoModel.php';
require_once __DIR__ . '/../database/models/CustoConsultaModel.php';
require_once __DIR__ . '/../database/models/ControleCreditoExternoModel.php';
require_once __DIR__ . '/UsuarioCreditoService.php';

class ProcessoFilaWorkerService
{
    private ProcessoFilaModel $filaModel;
    private ConsultaExternaModel $consultaModel;
    private PedidoUsuarioModel $pedidoModel;
    private TipoPedidoModel $tipoPedidoModel;
    private CustoConsultaModel $custoConsultaModel;
    private ControleCreditoExternoModel $creditoExternoModel;
    private ConsultaProcessosApiService $consultaApiService;
    private ProcessoDocumentoService $documentoService;
    private DocumentoService $dadosService;
    private UsuarioCreditoService $usuarioCreditoService;

    public function __construct(mysqli $mysqli)
    {
        $this->filaModel = new ProcessoFilaModel($mysqli);
        $this->consultaModel = new ConsultaExternaModel($mysqli);
        $this->pedidoModel = new PedidoUsuarioModel($mysqli);
        $this->tipoPedidoModel = new TipoPedidoModel($mysqli);
        $this->custoConsultaModel = new CustoConsultaModel($mysqli);
        $this->creditoExternoModel = new ControleCreditoExternoModel($mysqli);
        $this->consultaApiService = new ConsultaProcessosApiService();
        $this->documentoService = new ProcessoDocumentoService($mysqli);
        $this->dadosService = new DocumentoService($mysqli);
        $this->usuarioCreditoService = new UsuarioCreditoService();
    }

    public function processarProximo(): array
    {
        $item = $this->filaModel->buscarProximo();

        if (!$item) {
            $itemEsgotado = $this->filaModel->buscarProximoEsgotado();

            if ($itemEsgotado) {
                $resultado = $this->processarTentativasEsgotadas($itemEsgotado);
                $this->filaModel->marcarConcluido(
                    (int) $itemEsgotado['id_fila'],
                    $resultado['message'] ?? 'Tentativas esgotadas.'
                );

                return ['ok' => true, 'processed' => true, 'item' => $itemEsgotado, 'result' => $resultado];
            }

            return [
                'ok' => true,
                'processed' => false,
                'message' => 'Fila sem itens elegiveis agora.',
                'next_item' => $this->filaModel->buscarProximoAgendado(),
            ];
        }

        try {
            $resultado = $this->processarItem($item);

            if (($resultado['pending'] ?? false) === true) {
                $delay = max(30, (int) ($resultado['retry_after'] ?? $this->calcularIntervaloReprocessamento($item)));
                $prioridade = $this->calcularPrioridadeReprocessamento($item);
                $this->filaModel->reagendar((int) $item['id_fila'], $resultado['message'] ?? 'Processamento pendente.', $delay, $prioridade);
            } elseif (($resultado['ok'] ?? false) === true || ($resultado['terminal'] ?? false) === true) {
                $this->filaModel->marcarConcluido((int) $item['id_fila'], $resultado['message'] ?? 'Processado com sucesso.');
            } else {
                $this->filaModel->marcarErro((int) $item['id_fila'], $resultado['message'] ?? 'Erro ao processar item.');
            }

            return ['ok' => true, 'processed' => true, 'item' => $item, 'result' => $resultado];
        } catch (Throwable $exception) {
            $this->filaModel->marcarErro((int) $item['id_fila'], $exception->getMessage());

            return ['ok' => false, 'processed' => true, 'item' => $item, 'message' => $exception->getMessage()];
        }
    }

    public function processarItemAvulso(array $item): array
    {
        return $this->processarItem($item);
    }

    private function processarTentativasEsgotadas(array $item): array
    {
        $mensagem = 'Tempo limite de processamento esgotado pela fila.';
        $consultaId = (int) ($item['id_consulta'] ?? 0);

        if ($consultaId <= 0) {
            $pedidoId = (int) ($item['id_consulta_usuario'] ?? 0);
            $pedido = $pedidoId > 0 ? $this->pedidoModel->buscarPorId($pedidoId) : null;

            if ($pedido) {
                $this->estornarPedidoComDebito($pedido, $mensagem);
                return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
            }

            return ['ok' => false, 'terminal' => true, 'message' => $mensagem . ' Pedido nao encontrado.'];
        }

        $consulta = $this->consultaModel->buscarPorId($consultaId);

        if (!$consulta) {
            return ['ok' => false, 'terminal' => true, 'message' => $mensagem . ' Consulta externa nao encontrada.'];
        }

        $statusConsulta = strtolower((string) ($consulta['status_resultado'] ?? ''));

        if ($statusConsulta === 'sucesso') {
            return ['ok' => true, 'terminal' => true, 'message' => 'Consulta ja estava processada.'];
        }

        if (!in_array($statusConsulta, ['erro', 'estornado', 'expirado'], true)) {
            $this->consultaModel->marcarErro((int) $consulta['id_consulta'], $mensagem, [
                'id_fila' => $item['id_fila'] ?? null,
                'tipo_fila' => $item['tipo_fila'] ?? null,
                'tentativas' => $item['tentativas'] ?? null,
                'max_tentativas' => $item['max_tentativas'] ?? null,
            ]);
            $this->marcarPedidoErro((int) $consulta['id_consulta'], $mensagem);
        }

        return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
    }

    private function processarItem(array $item): array
    {
        $tipoFila = (string) ($item['tipo_fila'] ?? '');

        if ($tipoFila === 'pdf_processo') {
            return $this->processarPdf($item);
        }

        if ($tipoFila === 'consulta_processos' || $tipoFila === 'detalhes_processo') {
            return $this->processarConsultaProcessos($item);
        }

        if (in_array($tipoFila, ['dados_cpf', 'dados_cnpj'], true)) {
            return $this->processarDadosPessoais($item);
        }

        return ['ok' => false, 'message' => 'Tipo de fila desconhecido: ' . $tipoFila];
    }

    private function calcularIntervaloReprocessamento(array $item): int
    {
        $tentativa = max(1, (int) ($item['tentativas'] ?? 1));

        if ($tentativa <= 2) {
            return 30;
        }

        if ($tentativa <= 4) {
            return 60;
        }

        if ($tentativa <= 6) {
            return 300;
        }

        return 600;
    }

    private function calcularPrioridadeReprocessamento(array $item): int
    {
        $tentativa = max(1, (int) ($item['tentativas'] ?? 1));
        $prioridadeAtual = max(100, (int) ($item['prioridade'] ?? 100));

        return max($prioridadeAtual, min(1000, 100 + ($tentativa * 10)));
    }

    private function processarPdf(array $item): array
    {
        $consulta = $this->obterOuCriarConsultaParaItem($item, 'pdf_processo');

        if (!$consulta) {
            return ['ok' => false, 'message' => 'Consulta de PDF nao encontrada.'];
        }

        $statusConsulta = strtolower((string) ($consulta['status_resultado'] ?? ''));

        if ($statusConsulta === 'sucesso') {
            return ['ok' => true, 'terminal' => true, 'message' => 'PDF ja estava processado.'];
        }

        if (in_array($statusConsulta, ['erro', 'estornado', 'expirado'], true)) {
            return ['ok' => false, 'terminal' => true, 'message' => 'PDF ja estava finalizado com status ' . $statusConsulta . '.'];
        }

        $resultado = $this->documentoService->verificarPendentePorConsulta((int) $consulta['id_consulta']);

        if (($resultado['ok'] ?? false) === false) {
            return ['ok' => false, 'message' => $resultado['message'] ?? 'Erro ao processar PDF.'];
        }

        if (($resultado['pending'] ?? false) === true || ($resultado['ready'] ?? false) === false) {
            return ['ok' => true, 'pending' => true, 'message' => $resultado['message'] ?? 'PDF ainda em processamento.'];
        }

        return ['ok' => true, 'message' => 'PDF processado.'];
    }

    private function processarDadosPessoais(array $item): array
    {
        $consulta = $this->obterOuCriarConsultaParaItem($item, (string) ($item['tipo_fila'] ?? 'dados_cpf'));

        if (!$consulta) {
            return ['ok' => false, 'message' => 'Consulta de dados pessoais nao encontrada.'];
        }

        $statusConsulta = strtolower((string) ($consulta['status_resultado'] ?? ''));
        if ($statusConsulta === 'sucesso') {
            return ['ok' => true, 'message' => 'Consulta de dados ja estava processada.'];
        }

        if (in_array($statusConsulta, ['erro', 'estornado', 'expirado'], true)) {
            return ['ok' => false, 'terminal' => true, 'message' => 'Consulta ja estava finalizada com status ' . $statusConsulta . '.'];
        }

        $resultado = $this->dadosService->processarDadosPessoaisPendente((int) $consulta['id_consulta']);

        if (($resultado['ok'] ?? false) === false) {
            return ['ok' => false, 'terminal' => true, 'message' => $resultado['message'] ?? 'Erro ao processar dados pessoais.'];
        }

        if (($resultado['ready'] ?? false) !== true) {
            return ['ok' => true, 'pending' => true, 'message' => $resultado['message'] ?? 'Dados pessoais ainda em processamento.'];
        }

        return ['ok' => true, 'message' => 'Dados pessoais processados pela fila.'];
    }

    private function processarConsultaProcessos(array $item): array
    {
        $consulta = $this->obterOuCriarConsultaParaItem($item, 'consulta_processos');

        if (!$consulta) {
            return ['ok' => false, 'message' => 'Consulta externa nao encontrada.'];
        }

        $statusConsulta = strtolower((string) ($consulta['status_resultado'] ?? ''));
        if ($statusConsulta === 'sucesso') {
            return ['ok' => true, 'message' => 'Consulta ja estava processada.'];
        }

        if (in_array($statusConsulta, ['erro', 'estornado', 'expirado'], true)) {
            return ['ok' => false, 'terminal' => true, 'message' => 'Consulta ja estava finalizada com status ' . $statusConsulta . '.'];
        }

        $modalidadePedido = (string) ($consulta['modalidade_pedido'] ?? 'consulta_processos');
        $entrada = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));

        if ($modalidadePedido === 'detalhes_processo' && strlen($entrada) === 20) {
            return $this->processarDetalhesProcessoCnj($consulta);
        }

        $dados = $this->decodeJson($consulta['dados_json'] ?? null);
        $requestId = $dados['requestId'] ?? $dados['createResponse']['requestId'] ?? $dados['resultResponse']['requestId'] ?? null;

        if (!$requestId) {
            $criacao = $this->criarSolicitacaoExternaConsultaProcessos($consulta, $item);

            if (($criacao['ok'] ?? false) === false) {
                $mensagem = (string) ($criacao['message'] ?? 'Nao foi possivel criar a consulta externa.');
                $this->consultaModel->marcarErro((int) $consulta['id_consulta'], $mensagem, $criacao);
                $this->marcarPedidoErro((int) $consulta['id_consulta'], $mensagem);

                return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
            }

            $requestId = (string) ($criacao['raw']['requestId'] ?? '');

            if ($requestId === '') {
                $mensagem = 'A API nao retornou requestId para acompanhamento.';
                $this->consultaModel->marcarErro((int) $consulta['id_consulta'], $mensagem, $criacao);
                $this->marcarPedidoErro((int) $consulta['id_consulta'], $mensagem);

                return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
            }

            $this->consultaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], (string) ($criacao['message'] ?? 'Consulta externa criada.'), [
                'requestId' => $requestId,
                'createResponse' => $criacao['raw'],
            ]);

            $this->atualizarPayloadConsultaProcessos($item, [
                'request_id' => $requestId,
                'fornecedor' => (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                'modalidade_pedido' => (string) ($consulta['modalidade_pedido'] ?? 'consulta_processos'),
                'entrada_normalizada' => (string) ($consulta['entrada_normalizada'] ?? ''),
                'mensagem' => (string) ($criacao['message'] ?? 'Consulta externa criada.'),
            ]);
        }

        $entrada = (string) $consulta['entrada_normalizada'];
        $resultado = strlen(DocumentoValidator::onlyDigits($entrada)) === 20
            ? $this->consultaApiService->obterResultadoPartes((string) $requestId)
            : $this->consultaApiService->obterResultadoDocumentoCompleto((string) $requestId, 100, null, null, true);

        if (!$resultado['ok']) {
            $this->consultaModel->marcarErro((int) $consulta['id_consulta'], $resultado['message'], $resultado);
            $this->marcarPedidoErro((int) $consulta['id_consulta'], $resultado['message']);

            return ['ok' => false, 'terminal' => true, 'message' => $resultado['message']];
        }

        $status = strtolower((string) ($resultado['raw']['status'] ?? 'success'));

        if (in_array($status, ['fetching', 'pending', 'running', 'processing'], true)) {
            $this->consultaModel->marcarPendenteProcessamento((int) $consulta['id_consulta'], $resultado['message'], [
                'requestId' => $requestId,
                'resultResponse' => $resultado['raw'],
            ]);

            $this->atualizarPayloadConsultaProcessos($item, [
                'request_id' => $requestId,
                'fornecedor' => (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                'modalidade_pedido' => (string) ($consulta['modalidade_pedido'] ?? 'consulta_processos'),
                'entrada_normalizada' => (string) ($consulta['entrada_normalizada'] ?? ''),
                'mensagem' => $resultado['message'],
            ]);

            return [
                'ok' => true,
                'pending' => true,
                'retry_after' => $this->calcularIntervaloReprocessamento($item),
                'message' => $resultado['message'],
            ];
        }

        if (in_array($status, ['error', 'timeout', 'blocked'], true)) {
            $mensagem = $resultado['message'] ?: 'Consulta externa retornou status ' . $status . '.';
            $this->consultaModel->marcarErro((int) $consulta['id_consulta'], $mensagem, $resultado);
            $this->marcarPedidoErro((int) $consulta['id_consulta'], $mensagem);

            return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
        }

        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade($modalidadePedido);
        $creditosExternos = $this->custoExternoConsultaProcessos($consulta, $tipoPedido ?: []);
        $dataValidade = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->consultaModel->marcarSucesso(
            (int) $consulta['id_consulta'],
            [
                'message' => $resultado['message'],
                'raw' => array_merge($resultado['raw'], ['requestId' => $requestId]),
            ],
            $creditosExternos,
            $dataValidade
        );

        if ($creditosExternos > 0) {
            $this->creditoExternoModel->registrarDebito(
                (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                (int) $consulta['id_consulta'],
                $creditosExternos,
                'Consumo externo em ' . $modalidadePedido . ' pela fila'
            );
        }

        $pedido = $this->pedidoModel->buscarPendentePorConsulta((int) $consulta['id_consulta']);
        if ($pedido) {
            $this->pedidoModel->marcarSucessoWeb(
                (int) $pedido['id_consulta_usuario'],
                (int) $consulta['id_consulta'],
                (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                $creditosExternos,
                'Consulta processada pela fila.'
            );
        }

        return ['ok' => true, 'message' => 'Consulta processada pela fila.'];
    }

    private function processarDetalhesProcessoCnj(array $consulta): array
    {
        $consultaId = (int) ($consulta['id_consulta'] ?? 0);
        $entrada = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));

        if ($consultaId <= 0 || strlen($entrada) !== 20) {
            return ['ok' => false, 'terminal' => true, 'message' => 'Consulta CNJ invalida para processamento.'];
        }

        $resultado = $this->consultaApiService->consultarProcessoPorCnj($this->formatarCnj($entrada));

        if (!$resultado['ok']) {
            $mensagem = $resultado['message'] ?: 'Nao foi possivel consultar o processo por CNJ.';
            $this->consultaModel->marcarErro($consultaId, $mensagem, $resultado);
            $this->marcarPedidoErro($consultaId, $mensagem);

            return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
        }

        $status = strtolower((string) ($resultado['raw']['status'] ?? 'done'));

        if (in_array($status, ['fetching', 'pending', 'running', 'processing'], true)) {
            $this->consultaModel->marcarPendenteProcessamento($consultaId, $resultado['message'], [
                'resultResponse' => $resultado['raw'],
            ]);

            return [
                'ok' => true,
                'pending' => true,
                'retry_after' => $this->calcularIntervaloReprocessamento(['tentativas' => 1]),
                'message' => $resultado['message'] ?: 'Consulta por CNJ em processamento.',
            ];
        }

        if (in_array($status, ['error', 'timeout', 'blocked'], true)) {
            $mensagem = $resultado['message'] ?: 'Consulta externa retornou status ' . $status . '.';
            $this->consultaModel->marcarErro($consultaId, $mensagem, $resultado);
            $this->marcarPedidoErro($consultaId, $mensagem);

            return ['ok' => false, 'terminal' => true, 'message' => $mensagem];
        }

        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade('detalhes_processo');
        $creditosExternos = $this->custoExternoConsultaProcessos($consulta, $tipoPedido ?: []);
        $dataValidade = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->consultaModel->marcarSucesso(
            $consultaId,
            [
                'message' => $resultado['message'],
                'raw' => $resultado['raw'],
            ],
            $creditosExternos,
            $dataValidade
        );

        if ($creditosExternos > 0) {
            $this->creditoExternoModel->registrarDebito(
                (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                $consultaId,
                $creditosExternos,
                'Consumo externo em detalhes_processo pela fila'
            );
        }

        $pedido = $this->pedidoModel->buscarPendentePorConsulta($consultaId);
        if ($pedido) {
            $this->pedidoModel->marcarSucessoWeb(
                (int) $pedido['id_consulta_usuario'],
                $consultaId,
                (string) ($consulta['fornecedor'] ?? 'consultaprocesso'),
                $creditosExternos,
                'Consulta processada pela fila.'
            );
        }

        return ['ok' => true, 'message' => 'Consulta por CNJ processada pela fila.'];
    }

    private function obterOuCriarConsultaParaItem(array $item, string $modalidadePadrao): ?array
    {
        $consultaId = (int) ($item['id_consulta'] ?? 0);
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $forcarNovaConsulta = filter_var($payload['forcar_nova_consulta'] ?? $payload['ignorar_cache'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($consultaId > 0 && !$forcarNovaConsulta) {
            return $this->consultaModel->buscarPorId($consultaId);
        }

        $pedidoId = (int) ($item['id_consulta_usuario'] ?? 0);
        $pedido = $pedidoId > 0 ? $this->pedidoModel->buscarPorId($pedidoId) : null;

        $consultaPedidoId = (int) ($pedido['id_consulta'] ?? 0);
        if ($consultaPedidoId > 0 && !$forcarNovaConsulta) {
            return $this->consultaModel->buscarPorId($consultaPedidoId);
        }

        $modalidade = (string) ($payload['modalidade_pedido'] ?? $pedido['modalidade_pedido'] ?? $modalidadePadrao);
        $entradaOriginal = (string) ($payload['entrada_original'] ?? $pedido['entrada_original'] ?? $pedido['entrada_normalizada'] ?? '');
        $entradaNormalizada = (string) ($payload['entrada_normalizada'] ?? $pedido['entrada_normalizada'] ?? DocumentoValidator::onlyDigits($entradaOriginal));
        $fornecedor = (string) ($payload['fornecedor'] ?? $pedido['fornecedor'] ?? 'consultaprocesso');

        if ($modalidade === '' || $entradaNormalizada === '') {
            return null;
        }

        $novoConsultaId = $this->consultaModel->criarPendente(
            $modalidade,
            $entradaOriginal,
            $entradaNormalizada,
            $fornecedor
        );

        $this->consultaModel->marcarPendenteProcessamento($novoConsultaId, 'Consulta criada pela fila.', [
            'requestId' => $payload['request_id'] ?? null,
            'queued' => true,
            'id_fila' => $item['id_fila'] ?? null,
            'id_consulta_usuario' => $pedidoId ?: null,
        ]);

        if ($pedidoId > 0) {
            $this->pedidoModel->marcarPendenteProcessamento($pedidoId, $novoConsultaId, $fornecedor, 'Consulta criada pela fila.');
        }

        if (!empty($item['id_fila'])) {
            $this->filaModel->vincularConsulta((int) $item['id_fila'], $novoConsultaId);
            $payload['modalidade_pedido'] = $modalidade;
            $payload['entrada_original'] = $entradaOriginal;
            $payload['entrada_normalizada'] = $entradaNormalizada;
            $payload['fornecedor'] = $fornecedor;
            $payload['id_consulta_criada'] = $novoConsultaId;
            $this->filaModel->atualizarPayload((int) $item['id_fila'], $payload);
        }

        return $this->consultaModel->buscarPorId($novoConsultaId);
    }

    private function atualizarPayloadConsultaProcessos(array $item, array $payload): void
    {
        $idFila = (int) ($item['id_fila'] ?? 0);

        if ($idFila <= 0) {
            return;
        }

        $payloadAtual = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $this->filaModel->atualizarPayload($idFila, array_merge($payloadAtual, $payload));
    }

    private function custoExternoConsultaProcessos(array $consulta, array $tipoPedido): float
    {
        $modalidade = (string) ($consulta['modalidade_pedido'] ?? 'consulta_processos');
        $entrada = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));
        $chave = $modalidade;

        if ($modalidade === 'consulta_processos') {
            $chave = strlen($entrada) === 14 ? 'consulta_processos_cnpj' : 'consulta_processos_cpf';
        }

        $config = $this->custoConsultaModel->buscarPorChave($chave);

        if ($config) {
            return (float) ($config['custo_externo'] ?? 0);
        }

        return (float) ($tipoPedido['creditos_externo'] ?? 0);
    }

    private function criarSolicitacaoExternaConsultaProcessos(array $consulta, array $item): array
    {
        $entrada = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));

        if (strlen($entrada) === 20) {
            return $this->consultaApiService->criarConsultaPartes($entrada);
        }

        if (strlen($entrada) === 11 || strlen($entrada) === 14) {
            return $this->consultaApiService->criarConsultaDocumento($entrada);
        }

        return [
            'ok' => false,
            'message' => 'Entrada invalida para criar consulta externa.',
            'raw' => [
                'entrada_normalizada' => $entrada,
                'id_fila' => $item['id_fila'] ?? null,
            ],
        ];
    }

    private function marcarPedidoErro(int $consultaId, string $mensagem): void
    {
        $pedido = $this->pedidoModel->buscarPendentePorConsulta($consultaId);

        if ($pedido) {
            $this->estornarPedidoComDebito($pedido, $mensagem);
        }
    }

    private function estornarPedidoComDebito(array $pedido, string $mensagem): void
    {
        $pedidoId = (int) $pedido['id_consulta_usuario'];

        if (($pedido['status_resultado'] ?? '') === 'estornado' || !empty($pedido['credito_estorno_transacao_id'])) {
            $this->pedidoModel->marcarErro($pedidoId, $mensagem, 'estornado');
            return;
        }

        $creditosConsumidos = (float) ($pedido['creditos_usuario_consumidos'] ?? 0);
        $this->pedidoModel->marcarErro($pedidoId, $mensagem, $creditosConsumidos > 0 ? 'estornado' : 'erro');

        if ($creditosConsumidos <= 0) {
            return;
        }

        $estorno = $this->usuarioCreditoService->estornar(
            (int) $pedido['id_usuario'],
            $creditosConsumidos,
            $this->descricaoCreditoUsuario(
                (string) ($pedido['modalidade_pedido'] ?? 'consulta_processos'),
                (string) ($pedido['entrada_original'] ?? $pedido['entrada_normalizada'] ?? '')
            )
        );
        $this->pedidoModel->registrarTransacaoCredito($pedidoId, $estorno, true);
    }

    private function descricaoCreditoUsuario(string $modalidadePedido, string $entradaOriginal): string
    {
        $digits = DocumentoValidator::onlyDigits($entradaOriginal);
        $label = 'Consulta de processos';

        if ($modalidadePedido === 'detalhes_processo' || ($modalidadePedido === 'consulta_processos' && strlen($digits) === 20)) {
            $label = 'Consulta de processo por CNJ';
        } elseif ($modalidadePedido === 'consulta_processos' && strlen($digits) === 11) {
            $label = 'Consulta de processos por CPF';
        } elseif ($modalidadePedido === 'consulta_processos' && strlen($digits) === 14) {
            $label = 'Consulta de processos por CNPJ';
        } elseif ($modalidadePedido === 'pdf_processo') {
            $label = 'Download do processo na integra';
        }

        return sprintf(
            '%s em %s - entrada %s',
            $label,
            date('d/m/Y H:i'),
            trim($entradaOriginal)
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
}

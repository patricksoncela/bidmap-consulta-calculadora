<?php

require_once __DIR__ . '/DocumentoValidator.php';
require_once __DIR__ . '/ConsultaProcessosApiService.php';
require_once __DIR__ . '/UsuarioCreditoService.php';
require_once __DIR__ . '/ConsultaJobService.php';
require_once __DIR__ . '/../database/models/TipoPedidoModel.php';
require_once __DIR__ . '/../database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../database/models/ControleCreditoExternoModel.php';
require_once __DIR__ . '/../database/models/ProcessoFilaModel.php';
require_once __DIR__ . '/../database/models/CustoConsultaModel.php';

class ProcessoService
{
    private TipoPedidoModel $tipoPedidoModel;
    private ConsultaExternaModel $consultaExternaModel;
    private PedidoUsuarioModel $pedidoUsuarioModel;
    private ControleCreditoExternoModel $controleCreditoExternoModel;
    private ProcessoFilaModel $processoFilaModel;
    private CustoConsultaModel $custoConsultaModel;
    private ConsultaJobService $consultaJobService;
    private ConsultaProcessosApiService $apiService;
    private UsuarioCreditoService $usuarioCreditoService;

    public function __construct(
        mysqli $mysqli,
        ?ConsultaProcessosApiService $apiService = null,
        ?UsuarioCreditoService $usuarioCreditoService = null
    ) {
        $this->tipoPedidoModel = new TipoPedidoModel($mysqli);
        $this->consultaExternaModel = new ConsultaExternaModel($mysqli);
        $this->pedidoUsuarioModel = new PedidoUsuarioModel($mysqli);
        $this->controleCreditoExternoModel = new ControleCreditoExternoModel($mysqli);
        $this->processoFilaModel = new ProcessoFilaModel($mysqli);
        $this->custoConsultaModel = new CustoConsultaModel($mysqli);
        $this->consultaJobService = new ConsultaJobService($mysqli);
        $this->apiService = $apiService ?? new ConsultaProcessosApiService();
        $this->usuarioCreditoService = $usuarioCreditoService ?? new UsuarioCreditoService();
    }

    public function buscarPorDocumento(
        string $entrada,
        ?string $tipoDocumento = null,
        int $page = 1,
        int $perPage = 20,
        ?string $courtType = null,
        ?string $polarity = null,
        bool $ignorarCache = false
    ): array {
        $entradaOriginal = trim($entrada);
        $entradaNormalizada = DocumentoValidator::onlyDigits($entradaOriginal);
        $tipoDocumento = $tipoDocumento ?: DocumentoValidator::detectType($entradaNormalizada);

        if (!in_array($tipoDocumento, ['cpf', 'cnpj'], true)) {
            return $this->erro('Documento inválido. Informe um CPF ou CNPJ válido.');
        }

        if ($tipoDocumento === 'cpf' && !DocumentoValidator::isCpf($entradaNormalizada)) {
            return $this->erro('CPF inválido.');
        }

        if ($tipoDocumento === 'cnpj' && !DocumentoValidator::isCnpj($entradaNormalizada)) {
            return $this->erro('CNPJ inválido.');
        }

        return $this->executarConsultaAssincrona(
            'consulta_processos',
            $entradaOriginal,
            $entradaNormalizada,
            fn() => $this->apiService->criarConsultaDocumento($entradaNormalizada),
            fn(string $requestId) => $this->apiService->obterResultadoDocumentoCompleto($requestId, $perPage, $courtType, $polarity, true),
            $this->custoExternoConsultaDocumento($tipoDocumento),
            $ignorarCache
        );
    }

    public function consultarPartesPorCnj(string $entrada, ?string $instance = null): array
    {
        $entradaOriginal = trim($entrada);
        $entradaNormalizada = DocumentoValidator::onlyDigits($entradaOriginal);

        if (strlen($entradaNormalizada) !== 20) {
            return $this->erro('Número CNJ inválido.');
        }

        return $this->executarConsultaAssincrona(
            'detalhes_processo',
            $entradaOriginal,
            $entradaNormalizada,
            fn() => $this->apiService->criarConsultaPartes($entradaNormalizada, $instance),
            fn(string $requestId) => $this->apiService->obterResultadoPartes($requestId),
            $this->custoExternoConsultaCnj()
        );
    }

    public function buscarPorCnj(string $entrada, ?string $instance = null, bool $ignorarCache = false): array
    {
        $entradaOriginal = trim($entrada);
        $entradaNormalizada = DocumentoValidator::onlyDigits($entradaOriginal);

        if (strlen($entradaNormalizada) !== 20) {
            return $this->erro('Número CNJ inválido.');
        }

        return $this->executarConsultaAssincrona(
            'detalhes_processo',
            $entradaOriginal,
            $entradaNormalizada,
            fn() => $this->apiService->criarConsultaPartes($entradaNormalizada, $instance),
            fn(string $requestId) => $this->apiService->obterResultadoPartes($requestId),
            $this->custoExternoConsultaCnj(),
            $ignorarCache
        );
    }

    private function custoExternoConsultaDocumento(string $tipoDocumento): float
    {
        $chave = $tipoDocumento === 'cnpj' ? 'consulta_processos_cnpj' : 'consulta_processos_cpf';
        $config = $this->custoConsultaModel->buscarPorChave($chave);

        if ($config) {
            return (float) $config['custo_externo'];
        }

        $envKey = $tipoDocumento === 'cnpj'
            ? 'CONSULTA_PROCESSOS_CUSTO_CNPJ'
            : 'CONSULTA_PROCESSOS_CUSTO_CPF';

        return (float) str_replace(',', '.', (string) bidmap_env($envKey, '2.24'));
    }

    private function custoExternoConsultaCnj(): float
    {
        $config = $this->custoConsultaModel->buscarPorChave('detalhes_processo');

        if ($config) {
            return (float) $config['custo_externo'];
        }

        return (float) str_replace(',', '.', (string) bidmap_env('CONSULTA_PROCESSOS_CUSTO_CNJ', '0.84'));
    }

    private function executarConsultaAssincrona(
        string $modalidadePedido,
        string $entradaOriginal,
        string $entradaNormalizada,
        callable $criarConsulta,
        callable $obterResultado,
        ?float $creditosExternosOverride = null,
        bool $ignorarCache = false
    ): array {
        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade($modalidadePedido);

        if (!$tipoPedido) {
            return $this->erro('Modalidade de pedido não configurada.');
        }

        $fornecedor = $tipoPedido['fornecedor_padrao'] ?: 'consultaprocesso';
        $chaveCusto = $modalidadePedido === 'consulta_processos'
            ? (strlen($entradaNormalizada) === 14 ? 'consulta_processos_cnpj' : 'consulta_processos_cpf')
            : $modalidadePedido;
        $custoConfig = $this->custoConsultaModel->buscarPorChave($chaveCusto);
        $creditosUsuario = (float) ($custoConfig['creditos_usuario'] ?? $tipoPedido['creditos_usuario']);
        $idUsuario = $this->usuarioCreditoService->getUsuarioId();
        $saldoAnterior = $this->usuarioCreditoService->getSaldo($idUsuario);

        $cache = $this->consultaExternaModel->buscarCacheValido($modalidadePedido, $entradaNormalizada, $fornecedor);

        if ($cache && !$ignorarCache) {
            $pedidoId = $this->registrarPedidoCacheSemDebito(
                $idUsuario,
                $tipoPedido,
                $modalidadePedido,
                $entradaOriginal,
                $entradaNormalizada,
                $saldoAnterior,
                (int) $cache['id_consulta'],
                $fornecedor
            );

            if (!$pedidoId['ok']) {
                return $pedidoId;
            }

            return $this->sucesso([
                'origem' => 'cache',
                'pedido_id' => $pedidoId['pedido_id'],
                'consulta_id' => (int) $cache['id_consulta'],
                'dados' => $this->decodeJson($cache['dados_json']),
                'saldo_anterior' => $pedidoId['saldo_anterior'],
                'saldo_posterior' => $pedidoId['saldo_posterior'],
            ]);
        }

        $pendente = $this->consultaExternaModel->buscarPendente($modalidadePedido, $entradaNormalizada, $fornecedor);

        if ($pendente) {
            $requestId = $this->extrairRequestId($this->decodeJson($pendente['dados_json']));
            $pedidoPendente = $this->pedidoUsuarioModel->buscarPendentePorConsulta((int) $pendente['id_consulta']);

            return $this->sucesso([
                'pendente' => true,
                'origem' => 'fila',
                'pedido_id' => $pedidoPendente ? (int) $pedidoPendente['id_consulta_usuario'] : null,
                'consulta_id' => (int) $pendente['id_consulta'],
                'request_id' => $requestId,
                'dados' => $this->decodeJson($pendente['dados_json']) ?: [],
                'message' => (string) ($pendente['mensagem_resultado'] ?? 'Consulta aguardando processamento.'),
                'saldo_anterior' => $pedidoPendente ? (float) $pedidoPendente['saldo_anterior_usuario'] : null,
                'saldo_posterior' => $pedidoPendente ? (float) $pedidoPendente['saldo_posterior_usuario'] : null,
            ]);
        }

        $pedidoPendenteEntrada = $this->pedidoUsuarioModel->buscarPendentePorUsuarioEntrada(
            $idUsuario,
            $modalidadePedido,
            $entradaNormalizada
        );

        if ($pedidoPendenteEntrada) {
            return $this->sucesso([
                'pendente' => true,
                'origem' => 'fila',
                'pedido_id' => (int) $pedidoPendenteEntrada['id_consulta_usuario'],
                'consulta_id' => !empty($pedidoPendenteEntrada['id_consulta']) ? (int) $pedidoPendenteEntrada['id_consulta'] : null,
                'request_id' => null,
                'dados' => [],
                'message' => (string) ($pedidoPendenteEntrada['mensagem_resultado'] ?? 'Consulta aguardando processamento.'),
                'saldo_anterior' => (float) ($pedidoPendenteEntrada['saldo_anterior_usuario'] ?? $saldoAnterior),
                'saldo_posterior' => (float) ($pedidoPendenteEntrada['saldo_posterior_usuario'] ?? $saldoAnterior),
            ]);
        }

        $pedidoCobradoRecente = $this->pedidoUsuarioModel->buscarCobradoRecentePorUsuarioEntrada(
            $idUsuario,
            $modalidadePedido,
            $entradaNormalizada,
            $this->janelaAntiDuplicidadeSegundos()
        );

        if ($pedidoCobradoRecente) {
            return $this->resultadoPedidoExistente($pedidoCobradoRecente);
        }

        $pedido = $this->registrarPedidoComDebito(
            $idUsuario,
            $tipoPedido,
            $modalidadePedido,
            $entradaOriginal,
            $entradaNormalizada,
            $creditosUsuario,
            $saldoAnterior
        );

        if (!$pedido['ok']) {
            return $pedido;
        }

        $mensagemFila = 'Consulta adicionada à fila de processamento.';
        $this->pedidoUsuarioModel->marcarPendenteProcessamento($pedido['pedido_id'], null, $fornecedor, $mensagemFila);

        $this->enfileirarProcessamento(
            (string) ($tipoPedido['modalidade_pedido'] ?? $modalidadePedido),
            null,
            $pedido['pedido_id'],
            [
                'request_id' => null,
                'fornecedor' => $fornecedor,
                'modalidade_pedido' => $tipoPedido['modalidade_pedido'] ?? $modalidadePedido,
                'entrada_original' => $entradaOriginal,
                'entrada_normalizada' => $entradaNormalizada,
                'mensagem' => $mensagemFila,
            ]
        );

        return $this->sucesso([
            'pendente' => true,
            'origem' => 'fila',
            'pedido_id' => $pedido['pedido_id'],
            'consulta_id' => null,
            'request_id' => null,
            'dados' => [],
            'message' => $mensagemFila,
            'saldo_anterior' => $pedido['saldo_anterior'],
            'saldo_posterior' => $pedido['saldo_posterior'],
        ]);

        $criacao = $criarConsulta();

        if (!$criacao['ok']) {
            return $this->falharComEstorno(
                $consultaId,
                $pedido['pedido_id'],
                $idUsuario,
                $creditosUsuario,
                $modalidadePedido,
                $entradaNormalizada,
                $pedido['saldo_anterior'],
                $criacao['message'] ?: 'Não foi possível criar a consulta externa.',
                $criacao
            );
        }

        $requestId = $criacao['raw']['requestId'] ?? null;

        if (!$requestId) {
            return $this->falharComEstorno(
                $consultaId,
                $pedido['pedido_id'],
                $idUsuario,
                $creditosUsuario,
                $modalidadePedido,
                $entradaNormalizada,
                $pedido['saldo_anterior'],
                'A API não retornou requestId para acompanhamento.',
                $criacao
            );
        }

        $this->consultaExternaModel->marcarPendenteProcessamento($consultaId, $criacao['message'], [
            'requestId' => $requestId,
            'createResponse' => $criacao['raw'],
        ]);

        $this->pedidoUsuarioModel->marcarPendenteProcessamento($pedido['pedido_id'], $consultaId, $fornecedor, $criacao['message']);

        return $this->finalizarOuManterPendente(
            $consultaId,
            $pedido['pedido_id'],
            $fornecedor,
            $tipoPedido,
            $requestId,
            $obterResultado,
            true,
            $pedido['saldo_anterior'],
            $pedido['saldo_posterior'],
            null,
            $creditosExternosOverride
        );
    }

    private function finalizarOuManterPendente(
        int $consultaId,
        ?int $pedidoId,
        string $fornecedor,
        array $tipoPedido,
        string $requestId,
        callable $obterResultado,
        bool $registrarCreditoExterno,
        ?float $saldoAnterior = null,
        ?float $saldoPosterior = null,
        ?array $pedidoExistente = null,
        ?float $creditosExternosOverride = null
    ): array {
        $resultado = $obterResultado($requestId);

        if (!$resultado['ok']) {
            $mensagem = $resultado['message'] ?: 'Não foi possível obter o resultado da consulta externa.';
            $this->consultaExternaModel->marcarErro($consultaId, $mensagem, $resultado);

            if ($pedidoId !== null) {
                $this->estornarPedidoComDebito(
                    $pedidoId,
                    $mensagem,
                    $pedidoExistente,
                    (string) ($tipoPedido['modalidade_pedido'] ?? 'consulta_processos')
                );
            }

            return $this->erro($mensagem, [
                'consulta_id' => $consultaId,
                'pedido_id' => $pedidoId,
                'estornado' => $pedidoId !== null,
            ]);
        }

        $status = strtolower((string) ($resultado['raw']['status'] ?? 'success'));

        if (in_array($status, ['fetching', 'pending', 'running', 'processing'], true)) {
            $this->consultaExternaModel->marcarPendenteProcessamento($consultaId, $resultado['message'], [
                'requestId' => $requestId,
                'resultResponse' => $resultado['raw'],
            ]);

            if ($pedidoId !== null) {
                $this->pedidoUsuarioModel->marcarPendenteProcessamento($pedidoId, $consultaId, $fornecedor, $resultado['message']);
            }

            $this->enfileirarProcessamento(
                (string) ($tipoPedido['modalidade_pedido'] ?? 'consulta_processos'),
                $consultaId,
                $pedidoId,
                [
                    'request_id' => $requestId,
                    'fornecedor' => $fornecedor,
                    'modalidade_pedido' => $tipoPedido['modalidade_pedido'] ?? 'consulta_processos',
                    'mensagem' => $resultado['message'],
                ]
            );

            return $this->sucesso([
                'pendente' => true,
                'origem' => 'web',
                'pedido_id' => $pedidoId,
                'consulta_id' => $consultaId,
                'request_id' => $requestId,
                'dados' => $resultado['raw'],
                'message' => $resultado['message'],
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoPosterior,
            ]);
        }

        if (in_array($status, ['error', 'timeout', 'blocked'], true)) {
            $mensagem = $resultado['message'] ?: 'Consulta externa retornou status ' . $status . '.';
            $this->consultaExternaModel->marcarErro($consultaId, $mensagem, $resultado);

            if ($pedidoId !== null) {
                $this->estornarPedidoComDebito(
                    $pedidoId,
                    $mensagem,
                    $pedidoExistente,
                    (string) ($tipoPedido['modalidade_pedido'] ?? 'consulta_processos')
                );
            }

            return $this->erro($mensagem, [
                'consulta_id' => $consultaId,
                'pedido_id' => $pedidoId,
                'status_externo' => $status,
                'estornado' => $pedidoId !== null,
            ]);
        }

        $creditosExternos = $creditosExternosOverride ?? (float) ($tipoPedido['creditos_externo'] ?? 0);
        $dataValidade = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->consultaExternaModel->marcarSucesso(
            $consultaId,
            [
                'message' => $resultado['message'],
                'raw' => array_merge($resultado['raw'], ['requestId' => $requestId]),
            ],
            $creditosExternos,
            $dataValidade
        );

        if ($registrarCreditoExterno && $creditosExternos > 0) {
            $this->controleCreditoExternoModel->registrarDebito(
                $fornecedor,
                $consultaId,
                $creditosExternos,
                'Consumo externo em consulta_processos'
            );
        }

        if ($pedidoId !== null) {
            $this->pedidoUsuarioModel->marcarSucessoWeb($pedidoId, $consultaId, $fornecedor, $creditosExternos);
        }

        $this->processoFilaModel->marcarConcluidoPorConsulta(
            (string) ($tipoPedido['modalidade_pedido'] ?? 'consulta_processos'),
            $consultaId,
            'Consulta processada com sucesso.'
        );

        return $this->sucesso([
            'pendente' => false,
            'origem' => 'web',
            'pedido_id' => $pedidoId,
            'consulta_id' => $consultaId,
            'request_id' => $requestId,
            'dados' => array_merge($resultado['raw'], ['requestId' => $requestId]),
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
        ]);
    }

    private function registrarPedidoComDebito(
        int $idUsuario,
        array $tipoPedido,
        string $modalidadePedido,
        string $entradaOriginal,
        string $entradaNormalizada,
        float $creditosUsuario,
        float $saldoAnterior
    ): array {
        if ($saldoAnterior < $creditosUsuario) {
            $pedidoId = $this->pedidoUsuarioModel->criarPendente(
                $idUsuario,
                (int) $tipoPedido['id_tipo_pedido'],
                $modalidadePedido,
                $entradaOriginal,
                $entradaNormalizada,
                0,
                $saldoAnterior,
                $saldoAnterior
            );

            $this->pedidoUsuarioModel->marcarErro($pedidoId, 'Créditos insuficientes.', 'saldo_insuficiente');

            return $this->erro('Créditos insuficientes.', [
                'pedido_id' => $pedidoId,
                'saldo_anterior' => $saldoAnterior,
                'saldo_posterior' => $saldoAnterior,
            ]);
        }

        $debito = $this->usuarioCreditoService->debitar(
            $idUsuario,
            $creditosUsuario,
            $this->descricaoCreditoUsuario($modalidadePedido, $entradaOriginal)
        );

        $saldoDepoisDebito = (float) ($debito['saldo_posterior'] ?? ($saldoAnterior - $creditosUsuario));
        $saldoAntesDebito = (float) ($debito['saldo_anterior'] ?? $saldoAnterior);

        $pedidoId = $this->pedidoUsuarioModel->criarPendente(
            $idUsuario,
            (int) $tipoPedido['id_tipo_pedido'],
            $modalidadePedido,
            $entradaOriginal,
            $entradaNormalizada,
            $creditosUsuario,
            $saldoAntesDebito,
            $saldoDepoisDebito
        );
        $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $debito);

        return $this->sucesso([
            'pedido_id' => $pedidoId,
            'saldo_anterior' => $saldoAntesDebito,
            'saldo_posterior' => $saldoDepoisDebito,
        ]);
    }

    private function registrarPedidoCacheSemDebito(
        int $idUsuario,
        array $tipoPedido,
        string $modalidadePedido,
        string $entradaOriginal,
        string $entradaNormalizada,
        float $saldoAtual,
        int $consultaId,
        string $fornecedor
    ): array {
        $pedidoExistente = $this->pedidoUsuarioModel->buscarSucessoPorConsultaUsuario($idUsuario, $consultaId);

        if ($pedidoExistente) {
            return $this->sucesso([
                'pedido_id' => (int) $pedidoExistente['id_consulta_usuario'],
                'saldo_anterior' => $saldoAtual,
                'saldo_posterior' => $saldoAtual,
            ]);
        }

        $pedidoId = $this->pedidoUsuarioModel->criarPendente(
            $idUsuario,
            (int) $tipoPedido['id_tipo_pedido'],
            $modalidadePedido,
            $entradaOriginal,
            $entradaNormalizada,
            0.0,
            $saldoAtual,
            $saldoAtual
        );

        $this->pedidoUsuarioModel->marcarSucessoCache($pedidoId, $consultaId, $fornecedor);

        return $this->sucesso([
            'pedido_id' => $pedidoId,
            'saldo_anterior' => $saldoAtual,
            'saldo_posterior' => $saldoAtual,
        ]);
    }

    private function resultadoPedidoExistente(array $pedido): array
    {
        $status = strtolower((string) ($pedido['status_resultado'] ?? ''));
        $consultaId = (int) ($pedido['id_consulta'] ?? 0);

        if ($status === 'sucesso' && $consultaId > 0) {
            $consulta = $this->consultaExternaModel->buscarPorId($consultaId);
            $dados = $this->decodeJson($consulta['dados_json'] ?? null);

            if ($consulta && is_array($dados)) {
                return $this->sucesso([
                    'origem' => 'pedido_existente',
                    'pedido_id' => (int) $pedido['id_consulta_usuario'],
                    'consulta_id' => $consultaId,
                    'dados' => $dados,
                    'saldo_anterior' => isset($pedido['saldo_anterior_usuario']) ? (float) $pedido['saldo_anterior_usuario'] : null,
                    'saldo_posterior' => isset($pedido['saldo_posterior_usuario']) ? (float) $pedido['saldo_posterior_usuario'] : null,
                ]);
            }
        }

        return $this->sucesso([
            'pendente' => true,
            'origem' => 'pedido_existente',
            'pedido_id' => (int) $pedido['id_consulta_usuario'],
            'consulta_id' => $consultaId > 0 ? $consultaId : null,
            'request_id' => null,
            'dados' => [],
            'message' => (string) ($pedido['mensagem_resultado'] ?? 'Consulta aguardando processamento.'),
            'saldo_anterior' => isset($pedido['saldo_anterior_usuario']) ? (float) $pedido['saldo_anterior_usuario'] : null,
            'saldo_posterior' => isset($pedido['saldo_posterior_usuario']) ? (float) $pedido['saldo_posterior_usuario'] : null,
        ]);
    }

    private function falharComEstorno(
        int $consultaId,
        int $pedidoId,
        int $idUsuario,
        float $creditosUsuario,
        string $modalidadePedido,
        string $entradaNormalizada,
        float $saldoAntesDebito,
        string $mensagem,
        array $dadosErro
    ): array {
        $this->consultaExternaModel->marcarErro($consultaId, $mensagem, $dadosErro);
        $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, 'estornado');

        $estorno = $this->usuarioCreditoService->estornar(
            $idUsuario,
            $creditosUsuario,
            $this->descricaoCreditoUsuario($modalidadePedido, $entradaNormalizada)
        );
        $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $estorno, true);

        return $this->erro($mensagem, [
            'pedido_id' => $pedidoId,
            'consulta_id' => $consultaId,
            'saldo_anterior' => $saldoAntesDebito,
            'saldo_posterior' => (float) ($estorno['saldo_posterior'] ?? $saldoAntesDebito),
            'estornado' => true,
        ]);
    }

    private function estornarPedidoComDebito(
        int $pedidoId,
        string $mensagem,
        ?array $pedido = null,
        string $modalidadePadrao = 'consulta_processos'
    ): void {
        $pedido = $pedido ?: $this->pedidoUsuarioModel->buscarPorId($pedidoId);

        if (!$pedido) {
            $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, 'erro');
            return;
        }

        if (($pedido['status_resultado'] ?? '') === 'estornado' || !empty($pedido['credito_estorno_transacao_id'])) {
            $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, 'estornado');
            return;
        }

        $creditosConsumidos = (float) ($pedido['creditos_usuario_consumidos'] ?? 0);
        $this->pedidoUsuarioModel->marcarErro($pedidoId, $mensagem, $creditosConsumidos > 0 ? 'estornado' : 'erro');

        if ($creditosConsumidos <= 0) {
            return;
        }

        $estorno = $this->usuarioCreditoService->estornar(
            (int) $pedido['id_usuario'],
            $creditosConsumidos,
            $this->descricaoCreditoUsuario(
                (string) ($pedido['modalidade_pedido'] ?? $modalidadePadrao),
                (string) ($pedido['entrada_original'] ?? $pedido['entrada_normalizada'] ?? '')
            )
        );
        $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $estorno, true);
    }

    private function extrairRequestId(?array $dados): ?string
    {
        if (!$dados) {
            return null;
        }

        return $dados['requestId']
            ?? $dados['createResponse']['requestId']
            ?? $dados['resultResponse']['requestId']
            ?? null;
    }

    private function enfileirarProcessamento(string $tipoFila, ?int $consultaId, ?int $pedidoId, array $payload): void
    {
        try {
            if ($this->legacyMysqlQueueEnabled()) {
                $this->processoFilaModel->enfileirar($tipoFila, $consultaId, $pedidoId, $payload);
            }
        } catch (Throwable $exception) {
            // A fila não deve interromper a resposta principal ao usuário.
        }
        try {
            $this->consultaJobService->registrar($tipoFila, $consultaId, $pedidoId, $payload);
        } catch (Throwable $exception) {
            // O job Redis futuro tambem nao deve interromper a resposta principal.
        }
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
        }

        return sprintf(
            '%s em %s - entrada %s',
            $label,
            date('d/m/Y H:i'),
            trim($entradaOriginal)
        );
    }

    private function legacyMysqlQueueEnabled(): bool
    {
        $value = function_exists('bidmap_env')
            ? bidmap_env('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED', 'false')
            : (getenv('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED') ?: 'false');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function janelaAntiDuplicidadeSegundos(): int
    {
        $value = function_exists('bidmap_env')
            ? bidmap_env('CONSULTA_ANTI_DUPLICIDADE_SEGUNDOS', '120')
            : (getenv('CONSULTA_ANTI_DUPLICIDADE_SEGUNDOS') ?: '120');

        return max(1, (int) $value);
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

    private function decodeJson(?string $json): ?array
    {
        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function sucesso(array $data): array
    {
        return array_merge(['ok' => true], $data);
    }

    private function erro(string $mensagem, array $data = []): array
    {
        return array_merge([
            'ok' => false,
            'message' => $mensagem,
        ], $data);
    }
}

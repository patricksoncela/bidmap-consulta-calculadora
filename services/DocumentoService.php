<?php

require_once __DIR__ . '/DocumentoValidator.php';
require_once __DIR__ . '/HubDevService.php';
require_once __DIR__ . '/UsuarioCreditoService.php';
require_once __DIR__ . '/ConsultaJobService.php';
require_once __DIR__ . '/../database/models/TipoPedidoModel.php';
require_once __DIR__ . '/../database/models/ConsultaExternaModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/../database/models/ControleCreditoExternoModel.php';
require_once __DIR__ . '/../database/models/CustoConsultaModel.php';
require_once __DIR__ . '/../database/models/ProcessoFilaModel.php';

class DocumentoService
{
    private TipoPedidoModel $tipoPedidoModel;
    private ConsultaExternaModel $consultaExternaModel;
    private PedidoUsuarioModel $pedidoUsuarioModel;
    private ControleCreditoExternoModel $controleCreditoExternoModel;
    private CustoConsultaModel $custoConsultaModel;
    private ProcessoFilaModel $processoFilaModel;
    private ConsultaJobService $consultaJobService;
    private HubDevService $hubDevService;
    private UsuarioCreditoService $usuarioCreditoService;

    public function __construct(
        mysqli $mysqli,
        ?HubDevService $hubDevService = null,
        ?UsuarioCreditoService $usuarioCreditoService = null
    ) {
        $this->tipoPedidoModel = new TipoPedidoModel($mysqli);
        $this->consultaExternaModel = new ConsultaExternaModel($mysqli);
        $this->pedidoUsuarioModel = new PedidoUsuarioModel($mysqli);
        $this->controleCreditoExternoModel = new ControleCreditoExternoModel($mysqli);
        $this->custoConsultaModel = new CustoConsultaModel($mysqli);
        $this->processoFilaModel = new ProcessoFilaModel($mysqli);
        $this->consultaJobService = new ConsultaJobService($mysqli);
        $this->hubDevService = $hubDevService ?? new HubDevService();
        $this->usuarioCreditoService = $usuarioCreditoService ?? new UsuarioCreditoService();
    }

    public function consultarDadosPessoais(string $entrada, ?string $tipoDocumento = null, bool $ignorarCache = false): array
    {
        $entradaOriginal = trim($entrada);
        $entradaNormalizada = DocumentoValidator::onlyDigits($entradaOriginal);
        $tipoDocumento = $tipoDocumento ?: DocumentoValidator::detectType($entradaNormalizada);

        if (!in_array($tipoDocumento, ['cpf', 'cnpj'], true)) {
            return $this->erro('Documento invalido. Informe um CPF ou CNPJ valido.');
        }

        if ($tipoDocumento === 'cpf' && !DocumentoValidator::isCpf($entradaNormalizada)) {
            return $this->erro('CPF invalido.');
        }

        if ($tipoDocumento === 'cnpj' && !DocumentoValidator::isCnpj($entradaNormalizada)) {
            return $this->erro('CNPJ invalido.');
        }

        $modalidadePedido = $tipoDocumento === 'cpf' ? 'dados_cpf' : 'dados_cnpj';
        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade($modalidadePedido);

        if (!$tipoPedido) {
            return $this->erro('Modalidade de pedido nao configurada.');
        }

        $fornecedor = $tipoPedido['fornecedor_padrao'] ?: 'hubdev';
        $custoConfig = $this->custoConsultaModel->buscarPorChave($modalidadePedido);
        $creditosUsuario = (float) ($custoConfig['creditos_usuario'] ?? $tipoPedido['creditos_usuario']);
        $idUsuario = $this->usuarioCreditoService->getUsuarioId();

        $cache = $this->consultaExternaModel->buscarCacheValido($modalidadePedido, $entradaNormalizada, $fornecedor);

        if ($cache && !$ignorarCache) {
            $saldoAtual = $this->saldoAtualSeguro($idUsuario);
            $pedidoExistente = $this->pedidoUsuarioModel->buscarSucessoPorConsultaUsuario($idUsuario, (int) $cache['id_consulta']);
            $pedidoId = $pedidoExistente
                ? (int) $pedidoExistente['id_consulta_usuario']
                : $this->pedidoUsuarioModel->criarPendente(
                    $idUsuario,
                    (int) $tipoPedido['id_tipo_pedido'],
                    $modalidadePedido,
                    $entradaOriginal,
                    $entradaNormalizada,
                    0,
                    $saldoAtual,
                    $saldoAtual
                );

            if (!$pedidoExistente) {
                $this->pedidoUsuarioModel->marcarSucessoCache($pedidoId, (int) $cache['id_consulta'], $fornecedor);
            }

            return $this->sucesso([
                'origem' => 'cache',
                'pedido_id' => $pedidoId,
                'consulta_id' => (int) $cache['id_consulta'],
                'dados' => $this->decodeJson($cache['dados_json']),
                'saldo_anterior' => $saldoAtual,
                'saldo_posterior' => $saldoAtual,
            ]);
        }

        $pendente = $this->consultaExternaModel->buscarPendente($modalidadePedido, $entradaNormalizada, $fornecedor);

        if ($pendente) {
            $pedidoPendente = $this->pedidoUsuarioModel->buscarPendentePorConsulta((int) $pendente['id_consulta']);

            return $this->sucesso([
                'pendente' => true,
                'origem' => 'fila',
                'pedido_id' => $pedidoPendente ? (int) $pedidoPendente['id_consulta_usuario'] : null,
                'consulta_id' => (int) $pendente['id_consulta'],
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
                'dados' => [],
                'message' => (string) ($pedidoPendenteEntrada['mensagem_resultado'] ?? 'Consulta aguardando processamento.'),
                'saldo_anterior' => (float) ($pedidoPendenteEntrada['saldo_anterior_usuario'] ?? 0),
                'saldo_posterior' => (float) ($pedidoPendenteEntrada['saldo_posterior_usuario'] ?? 0),
            ]);
        }

        $saldoAnterior = $this->usuarioCreditoService->getSaldo($idUsuario);

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

                $mensagemFila = 'Consulta adicionada à fila de processamento.';
        $this->pedidoUsuarioModel->marcarPendenteProcessamento($pedidoId, null, $fornecedor, $mensagemFila);
        $this->enfileirarDadosPessoais($modalidadePedido, null, $pedidoId, $fornecedor, $mensagemFila, $entradaOriginal, $entradaNormalizada);
        return $this->sucesso([
            'pendente' => true,
            'origem' => 'fila',
            'pedido_id' => $pedidoId,
            'consulta_id' => null,
            'dados' => [],
            'message' => $mensagemFila,
            'saldo_anterior' => $saldoAntesDebito,
            'saldo_posterior' => $saldoDepoisDebito,
        ]);
    }

    public function processarDadosPessoaisPendente(int $consultaId): array
    {
        $consulta = $this->consultaExternaModel->buscarPorId($consultaId);

        if (!$consulta) {
            return $this->erro('Consulta de dados pessoais nao encontrada.');
        }

        $modalidadePedido = (string) ($consulta['modalidade_pedido'] ?? '');
        if (!in_array($modalidadePedido, ['dados_cpf', 'dados_cnpj'], true)) {
            return $this->erro('Modalidade de dados pessoais invalida.');
        }

        if (($consulta['status_resultado'] ?? '') === 'sucesso') {
            return $this->sucesso([
                'ready' => true,
                'consulta_id' => null,
                'dados' => $this->decodeJson($consulta['dados_json']),
            ]);
        }

        if (($consulta['status_resultado'] ?? '') !== 'pendente') {
            return $this->erro((string) ($consulta['mensagem_resultado'] ?? 'Consulta de dados pessoais nao esta pendente.'));
        }

        $entradaNormalizada = DocumentoValidator::onlyDigits((string) ($consulta['entrada_normalizada'] ?? ''));
        $tipoDocumento = $modalidadePedido === 'dados_cnpj' ? 'cnpj' : 'cpf';
        $hubResponse = $tipoDocumento === 'cpf'
            ? $this->hubDevService->consultarCpf($entradaNormalizada)
            : $this->hubDevService->consultarCnpj($entradaNormalizada);

        if (!$hubResponse['ok']) {
            $mensagem = $hubResponse['message'] ?: 'Consulta externa nao retornou resultado.';
            return $this->falharDadosComEstorno($consulta, $mensagem, $hubResponse);
        }

        $tipoPedido = $this->tipoPedidoModel->buscarPorModalidade($modalidadePedido);
        $custoConfig = $this->custoConsultaModel->buscarPorChave($modalidadePedido);
        $fornecedor = (string) ($consulta['fornecedor'] ?: ($tipoPedido['fornecedor_padrao'] ?? 'hubdev'));
        $creditosExternos = (float) ($custoConfig['custo_externo'] ?? ($hubResponse['consumed'] ?? 0));
        $dataValidade = $this->calcularDataValidade($tipoDocumento);
        $lastUpdateCnpj = $tipoDocumento === 'cnpj'
            ? ($hubResponse['result']['lastUpdate'] ?? $hubResponse['result']['last_update'] ?? null)
            : null;

        $this->consultaExternaModel->marcarSucesso(
            $consultaId,
            $hubResponse,
            $creditosExternos,
            $dataValidade,
            $lastUpdateCnpj
        );

        if ($creditosExternos > 0) {
            $this->controleCreditoExternoModel->registrarDebito(
                $fornecedor,
                $consultaId,
                $creditosExternos,
                'Consumo externo em ' . $modalidadePedido . ' pela fila'
            );
        }

        $pedido = $this->pedidoUsuarioModel->buscarPendentePorConsulta($consultaId);
        if ($pedido) {
            $this->pedidoUsuarioModel->marcarSucessoWeb(
                (int) $pedido['id_consulta_usuario'],
                $consultaId,
                $fornecedor,
                $creditosExternos,
                'Consulta de dados processada pela fila.'
            );
        }

        return $this->sucesso([
            'ready' => true,
            'consulta_id' => null,
            'dados' => $hubResponse['raw'],
        ]);
    }

    private function calcularDataValidade(string $tipoDocumento): string
    {
        $dias = $tipoDocumento === 'cnpj' ? 90 : 30;
        return date('Y-m-d H:i:s', strtotime('+' . $dias . ' days'));
    }

    private function saldoAtualSeguro(int $idUsuario): ?float
    {
        try {
            return $this->usuarioCreditoService->getSaldo($idUsuario);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function falharDadosComEstorno(array $consulta, string $mensagem, ?array $dadosErro = null): array
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

        if ($creditosConsumidos > 0) {
            $estorno = $this->usuarioCreditoService->estornar(
                (int) $pedido['id_usuario'],
                $creditosConsumidos,
                $this->descricaoCreditoUsuario(
                    (string) ($pedido['modalidade_pedido'] ?? $consulta['modalidade_pedido'] ?? 'dados_cpf'),
                    (string) ($pedido['entrada_original'] ?? $consulta['entrada_original'] ?? '')
                )
            );
            $this->pedidoUsuarioModel->registrarTransacaoCredito($pedidoId, $estorno, true);
        }

        return $this->erro($mensagem);
    }

    private function enfileirarDadosPessoais(
        string $modalidadePedido,
        ?int $consultaId,
        int $pedidoId,
        string $fornecedor,
        string $mensagem,
        string $entradaOriginal = '',
        string $entradaNormalizada = ''
    ): void
    {
        $payload = [
            'fornecedor' => $fornecedor,
            'modalidade_pedido' => $modalidadePedido,
            'entrada_original' => $entradaOriginal,
            'entrada_normalizada' => $entradaNormalizada,
            'mensagem' => $mensagem,
        ];

        try {
            if ($this->legacyMysqlQueueEnabled()) {
                $this->processoFilaModel->enfileirar(
                    $modalidadePedido,
                    $consultaId,
                    $pedidoId,
                    $payload
                );
            }
        } catch (Throwable $exception) {
            // A fila nao deve impedir o registro do pedido.
        }
        try {
            $this->consultaJobService->registrar($modalidadePedido, $consultaId, $pedidoId, $payload);
        } catch (Throwable $exception) {
            // O job Redis futuro tambem nao deve impedir o registro do pedido.
        }
    }

    private function decodeJson(?string $json): ?array
    {
        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function legacyMysqlQueueEnabled(): bool
    {
        $value = function_exists('bidmap_env')
            ? bidmap_env('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED', 'false')
            : (getenv('CONSULTA_LEGACY_MYSQL_QUEUE_ENABLED') ?: 'false');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function descricaoCreditoUsuario(string $modalidadePedido, string $entradaOriginal): string
    {
        $label = $modalidadePedido === 'dados_cnpj'
            ? 'Consulta de dados por CNPJ'
            : 'Consulta de dados por CPF';

        return sprintf(
            '%s em %s - documento %s',
            $label,
            date('d/m/Y H:i'),
            trim($entradaOriginal)
        );
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

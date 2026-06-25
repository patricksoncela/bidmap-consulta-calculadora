<?php

require_once __DIR__ . '/../database/models/ConsultaJobModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';

class ConsultaJobService
{
    private ConsultaJobModel $jobModel;
    private PedidoUsuarioModel $pedidoUsuarioModel;

    public function __construct(mysqli $mysqli)
    {
        $this->jobModel = new ConsultaJobModel($mysqli);
        $this->pedidoUsuarioModel = new PedidoUsuarioModel($mysqli);
    }

    public function registrar(
        string $tipoJob,
        ?int $idConsulta,
        ?int $idPedido,
        array $payload = [],
        int $prioridade = 100
    ): ?int {
        $pedido = $idPedido ? $this->pedidoUsuarioModel->buscarPorId($idPedido) : null;
        $idUsuario = (int) ($payload['id_usuario'] ?? $pedido['id_usuario'] ?? 0);

        if ($idUsuario <= 0) {
            return null;
        }

        $modalidadePedido = (string) ($payload['modalidade_pedido'] ?? $pedido['modalidade_pedido'] ?? $tipoJob);
        $entradaNormalizada = (string) ($payload['entrada_normalizada'] ?? $pedido['entrada_normalizada'] ?? '');
        $entradaOriginal = (string) ($payload['entrada_original'] ?? $pedido['entrada_original'] ?? $entradaNormalizada);
        $fornecedor = (string) ($payload['fornecedor'] ?? $pedido['fornecedor'] ?? '');
        $idTipoPedido = $this->nullableInt($payload['id_tipo_pedido'] ?? $pedido['id_tipo_pedido'] ?? null);

        $payload = array_merge($payload, [
            'tipo_job' => $tipoJob,
            'id_consulta' => $idConsulta,
            'id_consulta_usuario' => $idPedido,
            'modalidade_pedido' => $modalidadePedido,
            'entrada_original' => $entradaOriginal,
            'entrada_normalizada' => $entradaNormalizada,
            'fornecedor' => $fornecedor,
        ]);

        return $this->jobModel->criarOuAtualizar([
            'job_uuid' => $this->uuidV4(),
            'tipo_job' => $tipoJob,
            'fila_redis' => $this->filaRedisParaTipo($tipoJob, $modalidadePedido),
            'status_job' => 'pending',
            'id_usuario' => $idUsuario,
            'id_tipo_pedido' => $idTipoPedido,
            'id_consulta_usuario' => $idPedido,
            'id_consulta' => $idConsulta,
            'modalidade_pedido' => $modalidadePedido,
            'entrada_original' => $entradaOriginal,
            'entrada_normalizada' => $entradaNormalizada,
            'fornecedor' => $fornecedor,
            'prioridade' => $prioridade,
            'max_tentativas' => 30,
            'idempotency_key' => $this->idempotencyKey($tipoJob, $idConsulta, $idPedido, $entradaNormalizada),
            'payload' => $payload,
        ]);
    }

    private function filaRedisParaTipo(string $tipoJob, string $modalidadePedido): string
    {
        if ($tipoJob === 'pdf_processo' || $modalidadePedido === 'pdf_processo') {
            return $this->filaRedisEnv('CONSULTA_JOB_QUEUE_PDF_PROCESSO', 'consultas:pdf');
        }

        if ($tipoJob === 'dados_cpf' || $modalidadePedido === 'dados_cpf') {
            return $this->filaRedisEnv('CONSULTA_JOB_QUEUE_DADOS_CPF', 'consultas:pessoas');
        }

        if ($tipoJob === 'dados_cnpj' || $modalidadePedido === 'dados_cnpj') {
            return $this->filaRedisEnv('CONSULTA_JOB_QUEUE_DADOS_CNPJ', 'consultas:empresas');
        }

        if ($tipoJob === 'detalhes_processo' || $modalidadePedido === 'detalhes_processo') {
            return $this->filaRedisEnv(
                'CONSULTA_JOB_QUEUE_DETALHES_PROCESSO',
                $this->filaRedisEnv('CONSULTA_JOB_QUEUE_PROCESSOS', 'consultas:processos')
            );
        }

        return $this->filaRedisEnv('CONSULTA_JOB_QUEUE_PROCESSOS', 'consultas:processos');
    }

    private function filaRedisEnv(string $name, string $default): string
    {
        $value = function_exists('bidmap_env') ? trim((string) bidmap_env($name, '')) : '';

        return $value !== '' ? $value : $default;
    }

    private function idempotencyKey(string $tipoJob, ?int $idConsulta, ?int $idPedido, string $entradaNormalizada): string
    {
        $vinculo = $idPedido && $idPedido > 0
            ? 'pedido:' . $idPedido
            : ($idConsulta && $idConsulta > 0 ? 'consulta:' . $idConsulta : 'entrada:' . $entradaNormalizada);

        return hash('sha256', implode('|', [
            $tipoJob,
            $vinculo,
        ]));
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

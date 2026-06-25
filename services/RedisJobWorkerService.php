<?php

require_once __DIR__ . '/../database/models/ConsultaJobModel.php';
require_once __DIR__ . '/../database/models/PedidoUsuarioModel.php';
require_once __DIR__ . '/ProcessoFilaWorkerService.php';
require_once __DIR__ . '/RedisQueueClient.php';

class RedisJobWorkerService
{
    private ConsultaJobModel $jobModel;
    private PedidoUsuarioModel $pedidoModel;
    private ProcessoFilaWorkerService $processador;
    private RedisQueueClient $redis;
    private string $workerId;
    private array $queues;

    public function __construct(mysqli $mysqli, ?RedisQueueClient $redis = null, ?array $queues = null, ?string $workerId = null)
    {
        $this->jobModel = new ConsultaJobModel($mysqli);
        $this->pedidoModel = new PedidoUsuarioModel($mysqli);
        $this->processador = new ProcessoFilaWorkerService($mysqli);
        $this->redis = $redis ?? new RedisQueueClient();
        $this->workerId = $workerId ?: php_uname('n') . ':redis-worker:' . getmypid();
        $this->queues = $queues ?: $this->defaultQueues();
    }

    public function processarProximo(int $timeoutSeconds = 5): array
    {
        $message = $this->redis->popBlocking($this->queues, $timeoutSeconds);

        if ($message === null) {
            return [
                'ok' => true,
                'processed' => false,
                'message' => 'Redis sem jobs no intervalo.',
            ];
        }

        $idJob = (int) ($message['value'] ?? 0);

        if ($idJob <= 0) {
            return [
                'ok' => false,
                'processed' => true,
                'message' => 'Mensagem Redis sem id_job valido.',
                'redis_message' => $message,
            ];
        }

        try {
            $job = $this->jobModel->iniciarProcessamento($idJob, $this->workerId);
        } catch (Throwable $exception) {
            try {
                $this->republicarMensagem($message);
            } catch (Throwable $redisException) {
                throw new RuntimeException(
                    $exception->getMessage() . ' | falha_republicar_redis=' . $redisException->getMessage(),
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        if (!$job) {
            return [
                'ok' => true,
                'processed' => true,
                'id_job' => $idJob,
                'message' => 'Job ignorado por status nao processavel.',
            ];
        }

        try {
            $item = $this->jobParaItemFila($job);
            $resultado = $this->processador->processarItemAvulso($item);
            $this->sincronizarConsultaCriada($idJob, $item);
            $this->finalizarJob($idJob, $resultado, $item);

            return [
                'ok' => true,
                'processed' => true,
                'id_job' => $idJob,
                'item' => $item,
                'result' => $resultado,
            ];
        } catch (Throwable $exception) {
            $this->jobModel->marcarErro($idJob, $exception->getMessage(), [
                'exception' => get_class($exception),
                'id_job' => $idJob,
            ], $this->calcularIntervaloRetry($job));

            return [
                'ok' => false,
                'processed' => true,
                'id_job' => $idJob,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function finalizarJob(int $idJob, array $resultado, array $item): void
    {
        if (($resultado['pending'] ?? false) === true) {
            $retryAfter = max(30, (int) ($resultado['retry_after'] ?? $this->calcularIntervaloRetry($item)));
            $this->jobModel->marcarErro(
                $idJob,
                (string) ($resultado['message'] ?? 'Processamento pendente.'),
                ['resultado' => $resultado],
                $retryAfter
            );
            return;
        }

        if (($resultado['ok'] ?? false) === true || ($resultado['terminal'] ?? false) === true) {
            $this->jobModel->marcarConcluido($idJob, ['resultado' => $resultado]);
            return;
        }

        $this->jobModel->marcarErro(
            $idJob,
            (string) ($resultado['message'] ?? 'Erro ao processar job Redis.'),
            ['resultado' => $resultado],
            null
        );
    }

    private function sincronizarConsultaCriada(int $idJob, array &$item): void
    {
        if (!empty($item['id_consulta'])) {
            return;
        }

        $pedidoId = (int) ($item['id_consulta_usuario'] ?? 0);
        if ($pedidoId <= 0) {
            return;
        }

        $pedido = $this->pedidoModel->buscarPorId($pedidoId);
        $consultaId = (int) ($pedido['id_consulta'] ?? 0);

        if ($consultaId <= 0) {
            return;
        }

        $item['id_consulta'] = $consultaId;
        $this->jobModel->vincularConsulta($idJob, $consultaId);
    }

    private function jobParaItemFila(array $job): array
    {
        $payload = $this->decodeJson($job['payload_json'] ?? null);
        $tipoJob = (string) ($job['tipo_job'] ?? $payload['tipo_job'] ?? '');

        return [
            'id_fila' => null,
            'tipo_fila' => $tipoJob,
            'id_consulta' => $this->nullableInt($job['id_consulta'] ?? null),
            'id_consulta_usuario' => $this->nullableInt($job['id_consulta_usuario'] ?? null),
            'status_fila' => 'processando',
            'prioridade' => (int) ($job['prioridade'] ?? 100),
            'tentativas' => (int) ($job['tentativas'] ?? 1),
            'max_tentativas' => (int) ($job['max_tentativas'] ?? 30),
            'payload_json' => (string) ($job['payload_json'] ?? '{}'),
            'payload' => $payload,
            'id_job' => (int) ($job['id_job'] ?? 0),
        ];
    }

    private function defaultQueues(): array
    {
        $configured = trim((string) bidmap_env('REDIS_WORKER_QUEUES', ''));

        if ($configured !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        return [
            'consultas:processos',
            'consultas:pdf',
            'consultas:pessoas',
            'consultas:empresas',
        ];
    }

    private function republicarMensagem(array $message): void
    {
        $queue = trim((string) ($message['queue'] ?? ''));
        $value = trim((string) ($message['value'] ?? ''));

        if ($queue === '' || $value === '') {
            return;
        }

        $this->redis->push($queue, $value);
    }

    private function calcularIntervaloRetry(array $item): int
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

    private function decodeJson(?string $json): array
    {
        $decoded = $json ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

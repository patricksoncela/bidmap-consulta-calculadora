<?php

require_once __DIR__ . '/../database/models/ConsultaJobModel.php';
require_once __DIR__ . '/RedisQueueClient.php';

class RedisJobPublisherService
{
    private ConsultaJobModel $jobModel;
    private RedisQueueClient $redis;
    private string $publisherId;
    private int $reservationTtlSeconds;

    public function __construct(mysqli $mysqli, ?RedisQueueClient $redis = null, ?string $publisherId = null)
    {
        $this->jobModel = new ConsultaJobModel($mysqli);
        $this->redis = $redis ?? new RedisQueueClient();
        $this->publisherId = $publisherId ?: php_uname('n') . ':publisher:' . getmypid();
        $this->reservationTtlSeconds = max(30, (int) bidmap_env('REDIS_PUBLISHER_RESERVATION_TTL', '300'));
    }

    public function publicarPendentes(int $limit = 100): array
    {
        $processingStaleSeconds = max(60, (int) bidmap_env('REDIS_PROCESSING_STALE_SECONDS', '900'));
        $recuperados = $this->jobModel->recuperarProcessandoExpirado($processingStaleSeconds);
        $jobs = $this->jobModel->reservarPendentesParaRedis($limit, $this->publisherId, $this->reservationTtlSeconds);
        $resultado = [
            'recuperados' => $recuperados,
            'reservados' => count($jobs),
            'publicados' => 0,
            'falhas' => 0,
            'jobs' => [],
        ];

        foreach ($jobs as $job) {
            $idJob = (int) ($job['id_job'] ?? 0);
            $filaRedis = (string) ($job['fila_redis'] ?? '');

            if ($idJob <= 0 || $filaRedis === '') {
                $resultado['falhas']++;
                continue;
            }

            try {
                $this->redis->push($filaRedis, (string) $idJob);
                $this->jobModel->marcarEnfileiradoRedis($idJob);
                $resultado['publicados']++;
                $resultado['jobs'][] = [
                    'id_job' => $idJob,
                    'fila_redis' => $filaRedis,
                    'status' => 'publicado',
                ];
            } catch (Throwable $exception) {
                $resultado['falhas']++;
                $this->jobModel->liberarReservaRedis($idJob, $exception->getMessage());
                $resultado['jobs'][] = [
                    'id_job' => $idJob,
                    'fila_redis' => $filaRedis,
                    'status' => 'erro',
                    'erro' => $exception->getMessage(),
                ];
            }
        }

        return $resultado;
    }

    public function testarConexao(): bool
    {
        return $this->redis->ping();
    }
}

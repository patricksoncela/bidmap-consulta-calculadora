<?php

require_once __DIR__ . '/../consulta_tables.php';

class ConsultaJobModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function criarOuAtualizar(array $job): int
    {
        $jobs = consulta_table('jobs');

        $jobUuid = (string) $job['job_uuid'];
        $tipoJob = (string) $job['tipo_job'];
        $filaRedis = (string) $job['fila_redis'];
        $statusJob = (string) ($job['status_job'] ?? 'pending');
        $idUsuario = (int) $job['id_usuario'];
        $idTipoPedido = $this->nullableInt($job['id_tipo_pedido'] ?? null);
        $idPedido = $this->nullableInt($job['id_consulta_usuario'] ?? null);
        $idConsulta = $this->nullableInt($job['id_consulta'] ?? null);
        $modalidadePedido = $this->nullableString($job['modalidade_pedido'] ?? null);
        $entradaOriginal = $this->nullableString($job['entrada_original'] ?? null);
        $entradaNormalizada = $this->nullableString($job['entrada_normalizada'] ?? null);
        $fornecedor = $this->nullableString($job['fornecedor'] ?? null);
        $prioridade = (int) ($job['prioridade'] ?? 100);
        $maxTentativas = (int) ($job['max_tentativas'] ?? 30);
        $idempotencyKey = (string) $job['idempotency_key'];
        $availableAt = $this->nullableString($job['available_at'] ?? null);
        $payloadJson = $this->encodeJson($job['payload_json'] ?? $job['payload'] ?? []);

        $sql = "INSERT INTO {$jobs}
                (job_uuid, tipo_job, fila_redis, status_job, id_usuario, id_tipo_pedido,
                 id_consulta_usuario, id_consulta, modalidade_pedido, entrada_original,
                 entrada_normalizada, fornecedor, prioridade, max_tentativas,
                 idempotency_key, available_at, payload_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), ?)
                ON DUPLICATE KEY UPDATE
                    id_job = LAST_INSERT_ID(id_job),
                    fila_redis = VALUES(fila_redis),
                    status_job = IF(status_job IN ('processing', 'completed', 'failed', 'cancelled'), status_job, VALUES(status_job)),
                    id_tipo_pedido = COALESCE(VALUES(id_tipo_pedido), id_tipo_pedido),
                    id_consulta_usuario = COALESCE(VALUES(id_consulta_usuario), id_consulta_usuario),
                    id_consulta = COALESCE(VALUES(id_consulta), id_consulta),
                    modalidade_pedido = COALESCE(VALUES(modalidade_pedido), modalidade_pedido),
                    entrada_original = COALESCE(VALUES(entrada_original), entrada_original),
                    entrada_normalizada = COALESCE(VALUES(entrada_normalizada), entrada_normalizada),
                    fornecedor = COALESCE(VALUES(fornecedor), fornecedor),
                    prioridade = LEAST(prioridade, VALUES(prioridade)),
                    max_tentativas = VALUES(max_tentativas),
                    available_at = IF(status_job IN ('processing', 'completed', 'failed', 'cancelled'), available_at, VALUES(available_at)),
                    payload_json = VALUES(payload_json),
                    updated_at = NOW()";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param(
            'ssssiiiissssiisss',
            $jobUuid,
            $tipoJob,
            $filaRedis,
            $statusJob,
            $idUsuario,
            $idTipoPedido,
            $idPedido,
            $idConsulta,
            $modalidadePedido,
            $entradaOriginal,
            $entradaNormalizada,
            $fornecedor,
            $prioridade,
            $maxTentativas,
            $idempotencyKey,
            $availableAt,
            $payloadJson
        );
        $stmt->execute();
        $this->assertExecuted($stmt, 'criar ou atualizar job');
        $id = (int) ($stmt->insert_id ?: $this->buscarIdPorIdempotencyKey($idempotencyKey));
        $stmt->close();

        return $id;
    }

    public function buscarPorId(int $idJob): ?array
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare("SELECT * FROM {$jobs} WHERE id_job = ? LIMIT 1");
        $this->assertStatement($stmt, 'buscar job por id');
        $stmt->bind_param('i', $idJob);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function iniciarProcessamento(int $idJob, string $workerId): ?array
    {
        $jobs = consulta_table('jobs');

        $this->mysqli->begin_transaction();

        try {
            $stmt = $this->mysqli->prepare("SELECT * FROM {$jobs} WHERE id_job = ? LIMIT 1 FOR UPDATE");
            $this->assertStatement($stmt, 'buscar job para processamento');
            $stmt->bind_param('i', $idJob);
            $stmt->execute();
            $job = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$job || ($job['status_job'] ?? '') !== 'pending') {
                $this->mysqli->commit();
                return null;
            }

            if ((int) ($job['tentativas'] ?? 0) >= (int) ($job['max_tentativas'] ?? 30)) {
                $mensagem = 'Tentativas maximas do job esgotadas.';
                $stmt = $this->mysqli->prepare(
                    "UPDATE {$jobs}
                     SET status_job = 'failed',
                         last_error = ?,
                         finished_at = NOW(),
                         worker_id = NULL,
                         updated_at = NOW()
                     WHERE id_job = ?"
                );
                $this->assertStatement($stmt, 'marcar job esgotado');
                $stmt->bind_param('si', $mensagem, $idJob);
                $stmt->execute();
                $this->assertExecuted($stmt, 'marcar job esgotado');
                $stmt->close();
                $this->mysqli->commit();

                return null;
            }

            $stmt = $this->mysqli->prepare(
                "UPDATE {$jobs}
                 SET status_job = 'processing',
                     started_at = COALESCE(started_at, NOW()),
                     last_attempt_at = NOW(),
                     worker_id = ?,
                     tentativas = tentativas + 1,
                     updated_at = NOW()
                 WHERE id_job = ?"
            );
            $this->assertStatement($stmt, 'iniciar job');
            $stmt->bind_param('si', $workerId, $idJob);
            $stmt->execute();
            $this->assertExecuted($stmt, 'iniciar job');
            $stmt->close();

            $this->mysqli->commit();
            $job['tentativas'] = (int) ($job['tentativas'] ?? 0) + 1;
            $job['worker_id'] = $workerId;
            $job['status_job'] = 'processing';

            return $job;
        } catch (Throwable $exception) {
            $this->mysqli->rollback();
            throw $exception;
        }
    }

    public function buscarPendentesParaRedis(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $jobs = consulta_table('jobs');
        $sql = "SELECT *
                FROM {$jobs}
                WHERE status_job = 'pending'
                  AND (
                      redis_enqueued_at IS NULL
                      OR redis_enqueued_at < DATE_SUB(NOW(), INTERVAL 300 SECOND)
                  )
                  AND available_at <= NOW()
                ORDER BY prioridade ASC, available_at ASC, id_job ASC
                LIMIT ?";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public function reservarPendentesParaRedis(int $limit, string $publisherId, int $staleSeconds = 300): array
    {
        $limit = max(1, min(500, $limit));
        $staleSeconds = max(30, $staleSeconds);
        $jobs = consulta_table('jobs');

        $this->mysqli->begin_transaction();

        try {
            $sql = "SELECT *
                    FROM {$jobs}
                    WHERE status_job = 'pending'
                      AND (
                          redis_enqueued_at IS NULL
                          OR redis_enqueued_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                      )
                      AND available_at <= NOW()
                      AND (
                          worker_id IS NULL
                          OR updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                      )
                    ORDER BY prioridade ASC, available_at ASC, id_job ASC
                    LIMIT ?
                    FOR UPDATE";

            $stmt = $this->mysqli->prepare($sql);
            $this->assertStatement($stmt, $sql);
            $stmt->bind_param('iii', $staleSeconds, $staleSeconds, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            $ids = [];

            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
                $ids[] = (int) $row['id_job'];
            }

            $stmt->close();

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $types = 's' . str_repeat('i', count($ids));
                $params = array_merge([$publisherId], $ids);
                $refs = [];

                foreach ($params as $index => $value) {
                    $refs[$index] = &$params[$index];
                }

                $stmt = $this->mysqli->prepare(
                    "UPDATE {$jobs}
                     SET worker_id = ?,
                         redis_queue_attempts = redis_queue_attempts + 1,
                         updated_at = NOW()
                     WHERE id_job IN ({$placeholders})
                       AND status_job = 'pending'
                       AND (
                           redis_enqueued_at IS NULL
                           OR redis_enqueued_at < DATE_SUB(NOW(), INTERVAL {$staleSeconds} SECOND)
                       )"
                );
                $this->assertStatement($stmt, 'reservar jobs para redis');
                $stmt->bind_param($types, ...$refs);
                $stmt->execute();
                $this->assertExecuted($stmt, 'reservar jobs para redis');
                $stmt->close();
            }

            $this->mysqli->commit();

            return $rows;
        } catch (Throwable $exception) {
            $this->mysqli->rollback();
            throw $exception;
        }
    }

    public function recuperarProcessandoExpirado(int $staleSeconds = 900): int
    {
        $staleSeconds = max(60, $staleSeconds);
        $jobs = consulta_table('jobs');
        $mensagem = 'Processamento anterior abandonado; reagendado automaticamente.';

        $stmt = $this->mysqli->prepare(
            "UPDATE {$jobs}
             SET status_job = 'pending',
                 worker_id = NULL,
                 redis_enqueued_at = NULL,
                 last_error = ?,
                 next_retry_at = NOW(),
                 available_at = NOW(),
                 updated_at = NOW()
             WHERE status_job = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
               AND tentativas < max_tentativas"
        );
        $this->assertStatement($stmt, 'recuperar jobs processando expirados');
        $stmt->bind_param('si', $mensagem, $staleSeconds);
        $stmt->execute();
        $this->assertExecuted($stmt, 'recuperar jobs processando expirados');
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public function marcarEnfileiradoRedis(int $idJob): void
    {
        $this->atualizarStatusRedis($idJob, 'redis_enqueued_at = NOW(), worker_id = NULL, last_error = NULL');
    }

    public function vincularConsulta(int $idJob, int $idConsulta): void
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare(
            "UPDATE {$jobs}
             SET id_consulta = ?,
                 updated_at = NOW()
             WHERE id_job = ?
               AND (id_consulta IS NULL OR id_consulta = 0)"
        );
        $this->assertStatement($stmt, 'vincular consulta ao job');
        $stmt->bind_param('ii', $idConsulta, $idJob);
        $stmt->execute();
        $this->assertExecuted($stmt, 'vincular consulta ao job');
        $stmt->close();
    }

    public function liberarReservaRedis(int $idJob, string $erro): void
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare(
            "UPDATE {$jobs}
             SET worker_id = NULL,
                 last_error = ?,
                 updated_at = NOW()
             WHERE id_job = ?
               AND status_job = 'pending'
               AND redis_enqueued_at IS NULL"
        );
        $this->assertStatement($stmt, 'liberar reserva redis do job');
        $stmt->bind_param('si', $erro, $idJob);
        $stmt->execute();
        $this->assertExecuted($stmt, 'liberar reserva redis do job');
        $stmt->close();
    }

    public function marcarProcessando(int $idJob, string $workerId): void
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare(
            "UPDATE {$jobs}
             SET status_job = 'processing',
                 started_at = COALESCE(started_at, NOW()),
                 last_attempt_at = NOW(),
                 worker_id = ?,
                 tentativas = tentativas + 1,
                 updated_at = NOW()
             WHERE id_job = ?"
        );
        $this->assertStatement($stmt, 'marcar job processando');
        $stmt->bind_param('si', $workerId, $idJob);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar job processando');
        $stmt->close();
    }

    public function marcarConcluido(int $idJob, array $resultado = []): void
    {
        $resultadoJson = $this->encodeJson($resultado);
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare(
            "UPDATE {$jobs}
             SET status_job = 'completed',
                 resultado_json = ?,
                 finished_at = NOW(),
                 worker_id = NULL,
                 last_error = NULL,
                 updated_at = NOW()
             WHERE id_job = ?"
        );
        $this->assertStatement($stmt, 'marcar job concluido');
        $stmt->bind_param('si', $resultadoJson, $idJob);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar job concluido');
        $stmt->close();
    }

    public function marcarErro(int $idJob, string $erro, array $erroDetalhes = [], ?int $retrySeconds = null): void
    {
        $erroJson = $this->encodeJson($erroDetalhes);
        $retrySeconds = $retrySeconds === null ? null : max(0, $retrySeconds);
        $jobs = consulta_table('jobs');

        if ($retrySeconds === null) {
            $stmt = $this->mysqli->prepare(
                "UPDATE {$jobs}
                 SET status_job = 'failed',
                     last_error = ?,
                     erro_json = ?,
                     finished_at = NOW(),
                     worker_id = NULL,
                     updated_at = NOW()
                 WHERE id_job = ?"
            );
            $this->assertStatement($stmt, 'marcar job erro');
            $stmt->bind_param('ssi', $erro, $erroJson, $idJob);
        } else {
            $stmt = $this->mysqli->prepare(
                "UPDATE {$jobs}
                 SET status_job = 'pending',
                     last_error = ?,
                     erro_json = ?,
                     next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                     worker_id = NULL,
                     redis_enqueued_at = NULL,
                     updated_at = NOW()
                 WHERE id_job = ?"
            );
            $this->assertStatement($stmt, 'reagendar job erro');
            $stmt->bind_param('ssiii', $erro, $erroJson, $retrySeconds, $retrySeconds, $idJob);
        }

        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar job erro');
        $stmt->close();
    }

    private function buscarIdPorIdempotencyKey(string $idempotencyKey): int
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare("SELECT id_job FROM {$jobs} WHERE idempotency_key = ? LIMIT 1");
        $this->assertStatement($stmt, 'buscar job por chave idempotente');
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return (int) ($row['id_job'] ?? 0);
    }

    private function atualizarStatusRedis(int $idJob, string $setSql): void
    {
        $jobs = consulta_table('jobs');
        $stmt = $this->mysqli->prepare("UPDATE {$jobs} SET {$setSql}, updated_at = NOW() WHERE id_job = ?");
        $this->assertStatement($stmt, 'atualizar status redis do job');
        $stmt->bind_param('i', $idJob);
        $stmt->execute();
        $this->assertExecuted($stmt, 'atualizar status redis do job');
        $stmt->close();
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    private function assertStatement($stmt, string $context): void
    {
        if ($stmt instanceof mysqli_stmt) {
            return;
        }

        throw new RuntimeException('Erro ao preparar SQL de jobs (' . $context . '): ' . $this->mysqli->error);
    }

    private function assertExecuted(mysqli_stmt $stmt, string $context): void
    {
        if ($stmt->errno === 0) {
            return;
        }

        throw new RuntimeException('Erro ao executar SQL de jobs (' . $context . '): ' . $stmt->error);
    }
}

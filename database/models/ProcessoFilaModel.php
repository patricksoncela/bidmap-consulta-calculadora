<?php

require_once __DIR__ . '/../consulta_tables.php';

class ProcessoFilaModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function enfileirar(
        string $tipoFila,
        ?int $idConsulta,
        ?int $idPedido = null,
        array $payload = [],
        int $prioridade = 100
    ): int {
        $filaProcessos = consulta_table('fila_processos');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $maxTentativas = 30;

        $sql = "INSERT INTO {$filaProcessos}
                (tipo_fila, id_consulta, id_consulta_usuario, status_fila, prioridade, max_tentativas, payload_json)
                VALUES (?, ?, ?, 'pendente', ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id_consulta_usuario = COALESCE(VALUES(id_consulta_usuario), id_consulta_usuario),
                    status_fila = IF(status_fila IN ('concluido', 'processando'), status_fila, 'pendente'),
                    prioridade = LEAST(prioridade, VALUES(prioridade)),
                    max_tentativas = VALUES(max_tentativas),
                    payload_json = VALUES(payload_json),
                    disponivel_em = IF(status_fila IN ('concluido', 'processando'), disponivel_em, GREATEST(disponivel_em, NOW())),
                    updated_at = NOW()";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('siiiis', $tipoFila, $idConsulta, $idPedido, $prioridade, $maxTentativas, $payloadJson);
        $stmt->execute();
        $id = (int) ($stmt->insert_id ?: $this->buscarIdFila($tipoFila, $idConsulta, $idPedido));
        $stmt->close();

        return $id;
    }

    public function buscarProximo(): ?array
    {
        $filaProcessos = consulta_table('fila_processos');

        $this->mysqli->begin_transaction();

        $sql = "SELECT *
                FROM {$filaProcessos}
                WHERE status_fila IN ('pendente', 'erro')
                  AND disponivel_em <= NOW()
                  AND tentativas < max_tentativas
                ORDER BY prioridade ASC, disponivel_em ASC, id_fila ASC
                LIMIT 1
                FOR UPDATE";

        $result = $this->mysqli->query($sql);
        $item = $result ? ($result->fetch_assoc() ?: null) : null;

        if (!$item) {
            $this->mysqli->commit();
            return null;
        }

        $idFila = (int) $item['id_fila'];
        $stmt = $this->mysqli->prepare(
            "UPDATE {$filaProcessos}
             SET status_fila = 'processando',
                 locked_at = NOW(),
                 locked_by = ?,
                 tentativas = tentativas + 1,
                 updated_at = NOW()
             WHERE id_fila = ?"
        );
        $worker = php_uname('n') . ':' . getmypid();
        $stmt->bind_param('si', $worker, $idFila);
        $stmt->execute();
        $stmt->close();

        $this->mysqli->commit();

        $item['tentativas'] = (int) $item['tentativas'] + 1;
        $item['payload'] = $this->decodePayload($item['payload_json'] ?? null);

        return $item;
    }

    public function buscarProximoEsgotado(): ?array
    {
        $filaProcessos = consulta_table('fila_processos');

        $this->mysqli->begin_transaction();

        $sql = "SELECT *
                FROM {$filaProcessos}
                WHERE status_fila IN ('pendente', 'erro')
                  AND tentativas >= max_tentativas
                ORDER BY prioridade ASC, updated_at ASC, id_fila ASC
                LIMIT 1
                FOR UPDATE";

        $result = $this->mysqli->query($sql);
        $item = $result ? ($result->fetch_assoc() ?: null) : null;

        if (!$item) {
            $this->mysqli->commit();
            return null;
        }

        $idFila = (int) $item['id_fila'];
        $stmt = $this->mysqli->prepare(
            "UPDATE {$filaProcessos}
             SET status_fila = 'processando',
                 locked_at = NOW(),
                 locked_by = ?,
                 updated_at = NOW()
             WHERE id_fila = ?"
        );
        $worker = php_uname('n') . ':' . getmypid();
        $stmt->bind_param('si', $worker, $idFila);
        $stmt->execute();
        $stmt->close();

        $this->mysqli->commit();

        $item['payload'] = $this->decodePayload($item['payload_json'] ?? null);

        return $item;
    }

    public function buscarProximoAgendado(): ?array
    {
        $filaProcessos = consulta_table('fila_processos');
        $sql = "SELECT id_fila, tipo_fila, id_consulta, status_fila, tentativas, max_tentativas, disponivel_em, mensagem
                FROM {$filaProcessos}
                WHERE status_fila IN ('pendente', 'erro')
                  AND tentativas < max_tentativas
                ORDER BY disponivel_em ASC, prioridade ASC, id_fila ASC
                LIMIT 1";

        $result = $this->mysqli->query($sql);

        return $result ? ($result->fetch_assoc() ?: null) : null;
    }

    public function marcarConcluido(int $idFila, ?string $mensagem = null): void
    {
        $this->atualizarStatus($idFila, 'concluido', $mensagem, null);
    }

    public function marcarConcluidoPorConsulta(string $tipoFila, int $idConsulta, ?string $mensagem = null): void
    {
        $filaProcessos = consulta_table('fila_processos');
        $sql = "UPDATE {$filaProcessos}
                SET status_fila = 'concluido',
                    mensagem = ?,
                    locked_at = NULL,
                    locked_by = NULL,
                    updated_at = NOW()
                WHERE tipo_fila = ?
                  AND id_consulta = ?
                  AND status_fila IN ('pendente', 'erro', 'processando')";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('ssi', $mensagem, $tipoFila, $idConsulta);
        $stmt->execute();
        $stmt->close();
    }

    public function vincularConsulta(int $idFila, int $idConsulta): void
    {
        $filaProcessos = consulta_table('fila_processos');
        $stmt = $this->mysqli->prepare(
            "UPDATE {$filaProcessos}
             SET id_consulta = ?,
                 updated_at = NOW()
             WHERE id_fila = ?"
        );
        $stmt->bind_param('ii', $idConsulta, $idFila);
        $stmt->execute();
        $stmt->close();
    }

    public function marcarErro(int $idFila, string $mensagem, int $delaySeconds = 300): void
    {
        $this->atualizarStatus($idFila, 'erro', $mensagem, $delaySeconds);
    }

    public function reagendar(int $idFila, string $mensagem, int $delaySeconds = 300, ?int $prioridade = null): void
    {
        $this->atualizarStatus($idFila, 'pendente', $mensagem, $delaySeconds, $prioridade);
    }

    public function atualizarPayload(int $idFila, array $payload): void
    {
        $filaProcessos = consulta_table('fila_processos');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $this->mysqli->prepare(
            "UPDATE {$filaProcessos}
             SET payload_json = ?,
                 updated_at = NOW()
             WHERE id_fila = ?"
        );
        $stmt->bind_param('si', $payloadJson, $idFila);
        $stmt->execute();
        $stmt->close();
    }

    private function atualizarStatus(
        int $idFila,
        string $status,
        ?string $mensagem,
        ?int $delaySeconds,
        ?int $prioridade = null
    ): void
    {
        $filaProcessos = consulta_table('fila_processos');
        $delaySeconds = $delaySeconds === null ? null : max(0, $delaySeconds);

        if ($delaySeconds === null) {
            $sql = "UPDATE {$filaProcessos}
                    SET status_fila = ?,
                        mensagem = ?,
                        locked_at = NULL,
                        locked_by = NULL,
                        updated_at = NOW()
                    WHERE id_fila = ?";
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param('ssi', $status, $mensagem, $idFila);
        } else {
            $sql = "UPDATE {$filaProcessos}
                    SET status_fila = ?,
                        mensagem = ?,
                        locked_at = NULL,
                        locked_by = NULL,
                        disponivel_em = DATE_ADD(NOW(), INTERVAL ? SECOND),
                        prioridade = IF(? IS NULL, prioridade, ?),
                        updated_at = NOW()
                    WHERE id_fila = ?";
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param('ssiiii', $status, $mensagem, $delaySeconds, $prioridade, $prioridade, $idFila);
        }

        $stmt->execute();
        $stmt->close();
    }

    private function buscarIdFila(string $tipoFila, ?int $idConsulta, ?int $idPedido): int
    {
        $filaProcessos = consulta_table('fila_processos');

        if ($idConsulta !== null && $idConsulta > 0) {
            $stmt = $this->mysqli->prepare(
                "SELECT id_fila FROM {$filaProcessos} WHERE tipo_fila = ? AND id_consulta = ? LIMIT 1"
            );
            $stmt->bind_param('si', $tipoFila, $idConsulta);
        } else {
            $stmt = $this->mysqli->prepare(
                "SELECT id_fila FROM {$filaProcessos} WHERE tipo_fila = ? AND id_consulta_usuario = ? LIMIT 1"
            );
            $stmt->bind_param('si', $tipoFila, $idPedido);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $id = (int) (($result->fetch_assoc() ?: [])['id_fila'] ?? 0);
        $stmt->close();

        return $id;
    }

    private function decodePayload(?string $payload): array
    {
        $decoded = $payload ? json_decode($payload, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

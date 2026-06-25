<?php

require_once __DIR__ . '/../consulta_tables.php';

class ExtratoCreditoUsuarioModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function upsertMovimentacao(array $movimentacao): void
    {
        $table = consulta_table('extrato_creditos_usuario');
        $rawJson = json_encode($movimentacao['raw'] ?? $movimentacao, JSON_UNESCAPED_UNICODE);

        $stmt = $this->mysqli->prepare(
            "INSERT INTO {$table}
                (id_usuario, hash_movimentacao, idtransacao, tipo, valor, descricao, ativo, status,
                 data_movimentacao, origem, id_consulta_usuario, id_consulta, raw_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                idtransacao = VALUES(idtransacao),
                tipo = VALUES(tipo),
                valor = VALUES(valor),
                descricao = VALUES(descricao),
                ativo = VALUES(ativo),
                status = VALUES(status),
                data_movimentacao = VALUES(data_movimentacao),
                origem = VALUES(origem),
                id_consulta_usuario = COALESCE(VALUES(id_consulta_usuario), id_consulta_usuario),
                id_consulta = COALESCE(VALUES(id_consulta), id_consulta),
                raw_json = VALUES(raw_json),
                updated_at = NOW()"
        );

        $idUsuario = (int) $movimentacao['id_usuario'];
        $hash = (string) $movimentacao['hash_movimentacao'];
        $idTransacao = $this->nullableString($movimentacao['idtransacao'] ?? null);
        $tipo = (string) $movimentacao['tipo'];
        $valor = (float) $movimentacao['valor'];
        $descricao = (string) $movimentacao['descricao'];
        $ativo = !empty($movimentacao['ativo']) ? 1 : 0;
        $status = (string) $movimentacao['status'];
        $dataMovimentacao = (string) $movimentacao['data_movimentacao'];
        $origem = (string) ($movimentacao['origem'] ?? 'api_creditos');
        $idConsultaUsuario = $movimentacao['id_consulta_usuario'] ?? null;
        $idConsulta = $movimentacao['id_consulta'] ?? null;

        $stmt->bind_param(
            'isssdsisssiis',
            $idUsuario,
            $hash,
            $idTransacao,
            $tipo,
            $valor,
            $descricao,
            $ativo,
            $status,
            $dataMovimentacao,
            $origem,
            $idConsultaUsuario,
            $idConsulta,
            $rawJson
        );
        $stmt->execute();
        $stmt->close();
    }

    public function listarPorUsuario(int $idUsuario, int $limit = 100, int $offset = 0): array
    {
        $table = consulta_table('extrato_creditos_usuario');
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$table}
             WHERE id_usuario = ?
             ORDER BY data_movimentacao DESC, id_extrato DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $idUsuario, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}

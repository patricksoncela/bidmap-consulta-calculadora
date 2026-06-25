<?php

require_once __DIR__ . '/../consulta_tables.php';

class ConsultaExternaModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buscarCacheValido(string $modalidadePedido, string $entradaNormalizada, string $fornecedor): ?array
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT *
                FROM {$consultasExternas}
                WHERE modalidade_pedido = ?
                  AND entrada_normalizada = ?
                  AND fornecedor = ?
                  AND status_resultado = 'sucesso'
                  AND (data_validade IS NULL OR data_validade >= NOW())
                ORDER BY data_resposta DESC, id_consulta DESC
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("sss", $modalidadePedido, $entradaNormalizada, $fornecedor);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function buscarPendente(string $modalidadePedido, string $entradaNormalizada, string $fornecedor): ?array
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
        $stmt->bind_param("sss", $modalidadePedido, $entradaNormalizada, $fornecedor);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function criarPendente(string $modalidadePedido, string $entradaOriginal, string $entradaNormalizada, string $fornecedor): int
    {
        $status = 'pendente';
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "INSERT INTO {$consultasExternas}
                (modalidade_pedido, entrada_original, entrada_normalizada, fornecedor, status_resultado)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("sssss", $modalidadePedido, $entradaOriginal, $entradaNormalizada, $fornecedor, $status);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public function marcarSucesso(
        int $idConsulta,
        array $dados,
        float $creditosExternoConsumido,
        ?string $dataValidade,
        ?string $lastUpdateCnpj = null
    ): void {
        $status = 'sucesso';
        $mensagem = $dados['message'] ?? 'Consulta realizada com sucesso';
        $json = json_encode($dados['raw'] ?? $dados, JSON_UNESCAPED_UNICODE);

        $consultasExternas = consulta_table('consultas_externas');
        $sql = "UPDATE {$consultasExternas}
                SET data_resposta = NOW(),
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    dados_json = ?,
                    data_validade = ?,
                    creditos_externo_consumido = ?,
                    last_update_cnpj = ?
                WHERE id_consulta = ?";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("ssssdsi", $status, $mensagem, $json, $dataValidade, $creditosExternoConsumido, $lastUpdateCnpj, $idConsulta);
        $stmt->execute();
        $stmt->close();
    }

    public function marcarPendenteProcessamento(int $idConsulta, string $mensagem, array $dados): void
    {
        $status = 'pendente';
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);

        $consultasExternas = consulta_table('consultas_externas');
        $sql = "UPDATE {$consultasExternas}
                SET data_resposta = NOW(),
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    dados_json = ?
                WHERE id_consulta = ?";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("sssi", $status, $mensagem, $json, $idConsulta);
        $stmt->execute();
        $stmt->close();
    }

    public function marcarErro(int $idConsulta, string $mensagem, ?array $dados = null): void
    {
        $status = 'erro';
        $json = $dados ? json_encode($dados['raw'] ?? $dados, JSON_UNESCAPED_UNICODE) : null;

        $consultasExternas = consulta_table('consultas_externas');
        $sql = "UPDATE {$consultasExternas}
                SET data_resposta = NOW(),
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    dados_json = ?
                WHERE id_consulta = ?";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("sssi", $status, $mensagem, $json, $idConsulta);
        $stmt->execute();
        $stmt->close();
    }

    public function buscarPorId(int $idConsulta): ?array
    {
        $consultasExternas = consulta_table('consultas_externas');
        $stmt = $this->mysqli->prepare("SELECT * FROM {$consultasExternas} WHERE id_consulta = ? LIMIT 1");
        $stmt->bind_param("i", $idConsulta);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

<?php

require_once __DIR__ . '/../consulta_tables.php';

class TipoPedidoModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buscarPorModalidade(string $modalidadePedido): ?array
    {
        $tipoPedido = consulta_table('tipo_pedido');
        $sql = "SELECT id_tipo_pedido, modalidade_pedido, fornecedor_padrao, creditos_usuario,
                       valor_por_credito_usuario, valor_total_usuario, creditos_externo,
                       valor_por_credito_externo, valor_total_externo, is_ativo
                FROM {$tipoPedido}
                WHERE modalidade_pedido = ? AND is_ativo = 1
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("s", $modalidadePedido);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

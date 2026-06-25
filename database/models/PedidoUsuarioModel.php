<?php

require_once __DIR__ . '/../consulta_tables.php';

class PedidoUsuarioModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function criarPendente(
        int $idUsuario,
        int $idTipoPedido,
        string $modalidadePedido,
        string $entradaOriginal,
        string $entradaNormalizada,
        float $creditosUsuarioConsumidos,
        ?float $saldoAnteriorUsuario,
        ?float $saldoPosteriorUsuario
    ): int {
        $status = 'pendente';
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "INSERT INTO {$pedidosUsuarios}
                (id_usuario, id_tipo_pedido, modalidade_pedido, entrada_original, entrada_normalizada,
                 status_resultado, creditos_usuario_consumidos, saldo_anterior_usuario, saldo_posterior_usuario)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param(
            "iissssddd",
            $idUsuario,
            $idTipoPedido,
            $modalidadePedido,
            $entradaOriginal,
            $entradaNormalizada,
            $status,
            $creditosUsuarioConsumidos,
            $saldoAnteriorUsuario,
            $saldoPosteriorUsuario
        );
        $stmt->execute();
        $this->assertExecuted($stmt, 'criar pedido de usuario');
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    public function marcarSucessoCache(int $idPedido, int $idConsulta, string $fornecedor): void
    {
        $origem = 'cache';
        $status = 'sucesso';
        $mensagem = 'Resultado recuperado do cache interno';
        $creditoExternoConsumido = 0.0;

        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "UPDATE {$pedidosUsuarios}
                SET data_consulta_cache = NOW(),
                    id_consulta = ?,
                    origem = ?,
                    fornecedor = ?,
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    credito_externo_consumido = ?
                WHERE id_consulta_usuario = ?";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param("issssdi", $idConsulta, $origem, $fornecedor, $status, $mensagem, $creditoExternoConsumido, $idPedido);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar pedido como cache');
        $stmt->close();
    }

    public function marcarSucessoWeb(
        int $idPedido,
        int $idConsulta,
        string $fornecedor,
        float $creditoExternoConsumido,
        ?string $mensagem = null
    ): void {
        $origem = 'web';
        $status = 'sucesso';
        $mensagem = $mensagem ?? 'Resultado consultado no fornecedor externo';

        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "UPDATE {$pedidosUsuarios}
                SET id_consulta = ?,
                    origem = ?,
                    fornecedor = ?,
                    status_resultado = ?,
                    mensagem_resultado = ?,
                    credito_externo_consumido = ?
                WHERE id_consulta_usuario = ?";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param("issssdi", $idConsulta, $origem, $fornecedor, $status, $mensagem, $creditoExternoConsumido, $idPedido);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar pedido como web');
        $stmt->close();
    }

    public function marcarPendenteProcessamento(
        int $idPedido,
        ?int $idConsulta,
        string $fornecedor,
        ?string $mensagem = null
    ): void {
        $origem = 'web';
        $status = 'pendente';
        $mensagem = $mensagem ?? 'Consulta externa em processamento';

        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "UPDATE {$pedidosUsuarios}
                SET id_consulta = ?,
                    origem = ?,
                    fornecedor = ?,
                    status_resultado = ?,
                    mensagem_resultado = ?
                WHERE id_consulta_usuario = ?";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param("issssi", $idConsulta, $origem, $fornecedor, $status, $mensagem, $idPedido);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar pedido como pendente');
        $stmt->close();
    }

    public function marcarErro(int $idPedido, string $mensagem, string $status = 'erro'): void
    {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "UPDATE {$pedidosUsuarios}
                SET status_resultado = ?,
                    mensagem_resultado = ?
                WHERE id_consulta_usuario = ?";

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $stmt->bind_param("ssi", $status, $mensagem, $idPedido);
        $stmt->execute();
        $this->assertExecuted($stmt, 'marcar erro no pedido');
        $stmt->close();
    }

    public function registrarTransacaoCredito(int $idPedido, array $movimentacao, bool $isEstorno = false): void
    {
        $mapa = $isEstorno
            ? [
                'credito_estorno_transacao_id' => $movimentacao['transacao_id'] ?? null,
            ]
            : [
                'credito_transacao_id' => $movimentacao['transacao_id'] ?? null,
                'credito_descricao' => $movimentacao['descricao'] ?? null,
            ];

        $sets = [];
        $values = [];
        $types = '';

        foreach ($mapa as $coluna => $valor) {
            if (!$this->hasColumn($coluna)) {
                continue;
            }

            $sets[] = "{$coluna} = ?";
            $values[] = $valor;
            $types .= 's';
        }

        if (!$sets) {
            return;
        }

        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $sql = "UPDATE {$pedidosUsuarios} SET " . implode(', ', $sets) . " WHERE id_consulta_usuario = ?";
        $values[] = $idPedido;
        $types .= 'i';

        $stmt = $this->mysqli->prepare($sql);
        $this->assertStatement($stmt, $sql);
        $refs = [];
        foreach ($values as $index => $value) {
            $refs[$index] = &$values[$index];
        }
        $stmt->bind_param($types, ...$refs);
        $stmt->execute();
        $this->assertExecuted($stmt, 'registrar transacao de credito no pedido');
        $stmt->close();
    }

    public function buscarPorId(int $idPedido): ?array
    {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $stmt = $this->mysqli->prepare("SELECT * FROM {$pedidosUsuarios} WHERE id_consulta_usuario = ? LIMIT 1");
        $this->assertStatement($stmt, 'buscar pedido por id');
        $stmt->bind_param("i", $idPedido);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function buscarPendentePorConsulta(int $idConsulta): ?array
    {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$pedidosUsuarios}
             WHERE id_consulta = ?
               AND status_resultado = 'pendente'
             ORDER BY data_pedido DESC, id_consulta_usuario DESC
             LIMIT 1"
        );
        $this->assertStatement($stmt, 'buscar pedido pendente por consulta');
        $stmt->bind_param("i", $idConsulta);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function buscarPendentePorUsuarioEntrada(
        int $idUsuario,
        string $modalidadePedido,
        string $entradaNormalizada
    ): ?array {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$pedidosUsuarios}
             WHERE id_usuario = ?
               AND modalidade_pedido = ?
               AND entrada_normalizada = ?
               AND status_resultado = 'pendente'
             ORDER BY data_pedido DESC, id_consulta_usuario DESC
             LIMIT 1"
        );
        $this->assertStatement($stmt, 'buscar pedido pendente por usuario e entrada');
        $stmt->bind_param("iss", $idUsuario, $modalidadePedido, $entradaNormalizada);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function buscarSucessoPorConsultaUsuario(int $idUsuario, int $idConsulta): ?array
    {
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$pedidosUsuarios}
             WHERE id_usuario = ?
               AND id_consulta = ?
               AND status_resultado = 'sucesso'
             ORDER BY data_pedido DESC, id_consulta_usuario DESC
             LIMIT 1"
        );
        $this->assertStatement($stmt, 'buscar pedido de sucesso por usuario e consulta');
        $stmt->bind_param("ii", $idUsuario, $idConsulta);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public function buscarCobradoRecentePorUsuarioEntrada(
        int $idUsuario,
        string $modalidadePedido,
        string $entradaNormalizada,
        int $janelaSegundos
    ): ?array {
        $janelaSegundos = max(1, $janelaSegundos);
        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$pedidosUsuarios}
             WHERE id_usuario = ?
               AND modalidade_pedido = ?
               AND entrada_normalizada = ?
               AND status_resultado IN ('pendente', 'sucesso')
               AND creditos_usuario_consumidos > 0
               AND data_pedido >= DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY data_pedido DESC, id_consulta_usuario DESC
             LIMIT 1"
        );
        $this->assertStatement($stmt, 'buscar pedido cobrado recente por usuario e entrada');
        $stmt->bind_param("issi", $idUsuario, $modalidadePedido, $entradaNormalizada, $janelaSegundos);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    private function hasColumn(string $column): bool
    {
        static $cache = [];

        $pedidosUsuarios = consulta_table('pedidos_usuarios');
        $cacheKey = $pedidosUsuarios . '.' . $column;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $tableName = trim($pedidosUsuarios, '`');
        $stmt = $this->mysqli->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
        );
        $this->assertStatement($stmt, 'verificar coluna de pedidos_usuarios');
        $stmt->bind_param('ss', $tableName, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cache[$cacheKey] = ((int) ($row['total'] ?? 0)) > 0;

        return $cache[$cacheKey];
    }

    private function assertStatement($stmt, string $context): void
    {
        if ($stmt instanceof mysqli_stmt) {
            return;
        }

        throw new RuntimeException('Erro ao preparar SQL de pedidos_usuarios (' . $context . '): ' . $this->mysqli->error);
    }

    private function assertExecuted(mysqli_stmt $stmt, string $context): void
    {
        if ($stmt->errno === 0) {
            return;
        }

        throw new RuntimeException('Erro ao executar SQL de pedidos_usuarios (' . $context . '): ' . $stmt->error);
    }
}

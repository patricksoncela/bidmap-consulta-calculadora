<?php

require_once __DIR__ . '/../consulta_tables.php';

class ControleCreditoExternoModel
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buscarSaldoAtual(string $fornecedor): float
    {
        $controleCreditoExterno = consulta_table('controle_credito_externo');
        $sql = "SELECT saldo_final
                FROM {$controleCreditoExterno}
                WHERE fornecedor = ?
                ORDER BY data_operacao DESC, id_movimento_externo DESC
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("s", $fornecedor);
        $stmt->execute();
        $stmt->bind_result($saldoFinal);

        if (!$stmt->fetch()) {
            $stmt->close();
            return 0.0;
        }

        $stmt->close();
        return (float) $saldoFinal;
    }

    public function registrarDebito(string $fornecedor, ?int $idConsulta, float $creditosMovimentados, ?string $observacao = null): int
    {
        if ($idConsulta === null) {
            $saldoAnterior = $this->buscarSaldoAtual($fornecedor);
            $saldoFinal = $saldoAnterior - $creditosMovimentados;

            return $this->registrarMovimento($fornecedor, 'debito', null, $creditosMovimentados, $saldoAnterior, $saldoFinal, $observacao);
        }

        $lockName = sprintf('bidmap_debito_externo_%s_%d', preg_replace('/[^A-Za-z0-9_]/', '_', $fornecedor), $idConsulta);
        $lockObtained = $this->obterLock($lockName);

        try {
            $debitoExistente = $this->buscarDebitoExistente($fornecedor, $idConsulta);
            if ($debitoExistente !== null) {
                return $debitoExistente;
            }

            $saldoAnterior = $this->buscarSaldoAtual($fornecedor);
            $saldoFinal = $saldoAnterior - $creditosMovimentados;

            return $this->registrarMovimento($fornecedor, 'debito', $idConsulta, $creditosMovimentados, $saldoAnterior, $saldoFinal, $observacao);
        } finally {
            if ($lockObtained) {
                $this->liberarLock($lockName);
            }
        }
    }

    public function registrarCredito(string $fornecedor, float $creditosMovimentados, ?string $observacao = null): int
    {
        $saldoAnterior = $this->buscarSaldoAtual($fornecedor);
        $saldoFinal = $saldoAnterior + $creditosMovimentados;

        return $this->registrarMovimento($fornecedor, 'credito', null, $creditosMovimentados, $saldoAnterior, $saldoFinal, $observacao);
    }

    public function registrarAjuste(string $fornecedor, float $saldoFinal, ?string $observacao = null): int
    {
        $saldoAnterior = $this->buscarSaldoAtual($fornecedor);
        $creditosMovimentados = $saldoFinal - $saldoAnterior;

        return $this->registrarMovimento($fornecedor, 'ajuste', null, $creditosMovimentados, $saldoAnterior, $saldoFinal, $observacao);
    }

    private function registrarMovimento(
        string $fornecedor,
        string $operacao,
        ?int $idConsulta,
        float $creditosMovimentados,
        float $saldoAnterior,
        float $saldoFinal,
        ?string $observacao
    ): int {
        $controleCreditoExterno = consulta_table('controle_credito_externo');
        $sql = "INSERT INTO {$controleCreditoExterno}
                (fornecedor, operacao, id_consulta, creditos_movimentados, saldo_anterior, saldo_final, observacao)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("ssiddds", $fornecedor, $operacao, $idConsulta, $creditosMovimentados, $saldoAnterior, $saldoFinal, $observacao);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function buscarDebitoExistente(string $fornecedor, int $idConsulta): ?int
    {
        $controleCreditoExterno = consulta_table('controle_credito_externo');
        $sql = "SELECT id_movimento_externo
                FROM {$controleCreditoExterno}
                WHERE fornecedor = ?
                  AND operacao = 'debito'
                  AND id_consulta = ?
                ORDER BY id_movimento_externo ASC
                LIMIT 1";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('si', $fornecedor, $idConsulta);
        $stmt->execute();
        $stmt->bind_result($idMovimento);

        if (!$stmt->fetch()) {
            $stmt->close();
            return null;
        }

        $stmt->close();
        return (int) $idMovimento;
    }

    private function obterLock(string $lockName): bool
    {
        $stmt = $this->mysqli->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->bind_result($result);
        $stmt->fetch();
        $stmt->close();

        return (int) $result === 1;
    }

    private function liberarLock(string $lockName): void
    {
        $stmt = $this->mysqli->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->close();
    }
}

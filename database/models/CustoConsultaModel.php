<?php

require_once __DIR__ . '/../consulta_tables.php';

class CustoConsultaModel
{
    private mysqli $mysqli;
    private string $table;
    private string $tipoPedidoTable;

    private array $labels = [
        'dados_cpf' => ['Dados pessoais por CPF', 'Consulta de dados pessoais por CPF.', 10],
        'dados_cnpj' => ['Dados empresariais por CNPJ', 'Consulta de dados cadastrais de CNPJ.', 20],
        'consulta_processos' => ['Listar processos por CPF/CNPJ', 'Lista processos vinculados ao CPF ou CNPJ informado.', 30],
        'detalhes_processo' => ['Consultar processo por CNJ', 'Busca detalhes do processo diretamente pelo número CNJ.', 40],
        'pdf_processo' => ['Baixar processo na íntegra', 'Gera e salva o PDF completo do processo.', 50],
        'consulta_ia' => ['Consulta IA', 'Consulta de IA.', 60],
    ];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->table = consulta_table('custos');
        $this->tipoPedidoTable = consulta_table('tipo_pedido');
    }

    public function listar(): array
    {
        $result = $this->mysqli->query(
            "SELECT *
             FROM {$this->table}
             WHERE is_ativo = 1
             ORDER BY ordem ASC, id_custo ASC"
        );

        if (!$result) {
            return $this->listarFallbackTipoPedido();
        }

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->formatarLinhaCusto($row);
        }

        return $rows;
    }

    public function buscarPorChave(string $chave): ?array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$this->table}
             WHERE chave = ?
               AND is_ativo = 1
             LIMIT 1"
        );
        $stmt->bind_param('s', $chave);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($row) {
            return $this->formatarLinhaCusto($row);
        }

        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$this->table}
             WHERE modalidade_pedido = ?
               AND is_ativo = 1
             ORDER BY ordem ASC, id_custo ASC
             LIMIT 1"
        );
        $stmt->bind_param('s', $chave);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($row) {
            return $this->formatarLinhaCusto($row);
        }

        return $this->buscarFallbackTipoPedido($chave);
    }

    public function atualizar(string $chave, float $creditosUsuario, float $custoExterno, string $observacao): void
    {
        $linhaCusto = $this->buscarLinhaCustoPorChave($chave);

        if (!$linhaCusto) {
            $fallback = $this->buscarFallbackTipoPedido($chave);

            if (!$fallback) {
                return;
            }

            $modalidade = (string) $fallback['modalidade_pedido'];
            $stmt = $this->mysqli->prepare(
                "UPDATE {$this->tipoPedidoTable}
                 SET creditos_usuario = ?,
                     creditos_externo = ?,
                     updated_at = NOW()
                 WHERE modalidade_pedido = ?"
            );
            $stmt->bind_param('dds', $creditosUsuario, $custoExterno, $modalidade);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE {$this->table}
             SET creditos_usuario = ?,
                 custo_externo = ?,
                 observacao = ?,
                 updated_at = NOW()
             WHERE chave = ?"
        );
        $stmt->bind_param('ddss', $creditosUsuario, $custoExterno, $observacao, $chave);
        $stmt->execute();
        $stmt->close();
    }

    private function listarFallbackTipoPedido(): array
    {
        $result = $this->mysqli->query(
            "SELECT *
             FROM {$this->tipoPedidoTable}
             WHERE is_ativo = 1
             ORDER BY id_tipo_pedido ASC"
        );

        if (!$result) {
            return [];
        }

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->formatarLinhaTipoPedido($row, (string) ($row['modalidade_pedido'] ?? ''));
        }

        usort($rows, static fn (array $a, array $b): int => ((int) $a['ordem']) <=> ((int) $b['ordem']));

        return $rows;
    }

    private function buscarFallbackTipoPedido(string $chave): ?array
    {
        $modalidade = strpos($chave, 'consulta_processos_') === 0 ? 'consulta_processos' : $chave;
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$this->tipoPedidoTable}
             WHERE modalidade_pedido = ?
               AND is_ativo = 1
             LIMIT 1"
        );
        $stmt->bind_param('s', $modalidade);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row ? $this->formatarLinhaTipoPedido($row, $chave) : null;
    }

    private function buscarLinhaCustoPorChave(string $chave): ?array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT *
             FROM {$this->table}
             WHERE chave = ?
               AND is_ativo = 1
             LIMIT 1"
        );
        $stmt->bind_param('s', $chave);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    private function formatarLinhaCusto(array $row): array
    {
        return [
            'id_custo' => (int) ($row['id_custo'] ?? 0),
            'chave' => (string) ($row['chave'] ?? ''),
            'operacao' => (string) ($row['operacao'] ?? ''),
            'modalidade_pedido' => (string) ($row['modalidade_pedido'] ?? ''),
            'fornecedor' => (string) ($row['fornecedor'] ?? ''),
            'creditos_usuario' => (float) ($row['creditos_usuario'] ?? 0),
            'custo_externo' => (float) ($row['custo_externo'] ?? 0),
            'observacao' => (string) ($row['observacao'] ?? ''),
            'ordem' => (int) ($row['ordem'] ?? 100),
            'is_ativo' => (int) ($row['is_ativo'] ?? 1),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function formatarLinhaTipoPedido(array $row, string $chave): array
    {
        $modalidade = (string) ($row['modalidade_pedido'] ?? '');
        [$operacao, $observacao, $ordem] = $this->labels[$modalidade] ?? [
            ucfirst(str_replace('_', ' ', $modalidade)),
            '',
            100,
        ];

        return [
            'id_custo' => (int) ($row['id_tipo_pedido'] ?? 0),
            'chave' => $chave !== '' ? $chave : $modalidade,
            'operacao' => $operacao,
            'modalidade_pedido' => $modalidade,
            'fornecedor' => (string) ($row['fornecedor_padrao'] ?? ''),
            'creditos_usuario' => (float) ($row['creditos_usuario'] ?? 0),
            'custo_externo' => (float) ($row['creditos_externo'] ?? 0),
            'observacao' => $observacao,
            'ordem' => $ordem,
            'is_ativo' => (int) ($row['is_ativo'] ?? 1),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

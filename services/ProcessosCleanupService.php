<?php

require_once __DIR__ . '/../database/consulta_tables.php';

class ProcessosCleanupService
{
    private mysqli $mysqli;
    private string $pdfStorageDir;
    private int $pdfRetentionDays;
    private int $jsonCompactDays;

    public function __construct(mysqli $mysqli, ?string $pdfStorageDir = null)
    {
        $this->mysqli = $mysqli;
        $this->pdfStorageDir = $this->resolverStorageDir($pdfStorageDir ?? bidmap_env('PROCESSO_RAPIDO_STORAGE_DIR', 'storage/processos_pdf'));
        $this->pdfRetentionDays = max(1, (int) bidmap_env('PROCESSO_RAPIDO_PDF_RETENTION_DAYS', '30'));
        $this->jsonCompactDays = max(1, (int) bidmap_env('PROCESSOS_CLEANUP_JSON_COMPACT_DAYS', '90'));
    }

    private function resolverStorageDir(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return dirname(__DIR__) . '/storage/processos_pdf';
        }

        if (preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $path)) {
            return $path;
        }

        $appRoot = dirname(__DIR__);
        $relativePath = ltrim($path, '/\\');
        $primary = $appRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (is_dir($primary)) {
            return $primary;
        }

        $shared = dirname($appRoot) . DIRECTORY_SEPARATOR . $relativePath;

        if (is_dir($shared)) {
            return $shared;
        }

        return $primary;
    }

    public function executar(bool $dryRun = false): array
    {
        $resultado = [
            'dry_run' => $dryRun,
            'pdfs_removidos' => 0,
            'marcadores_removidos' => 0,
            'consultas_pdf_expiradas' => 0,
            'consultas_pdf_sucesso_sem_arquivo' => 0,
            'consultas_json_compactadas' => 0,
            'erros' => [],
        ];

        $this->limparPdfsLocais($dryRun, $resultado);
        $this->normalizarValidadePdf($dryRun, $resultado);
        $this->expirarConsultasPdfSemArquivo($dryRun, $resultado);
        $this->compactarJsonVencido($dryRun, $resultado);

        return $resultado;
    }

    private function normalizarValidadePdf(bool $dryRun, array &$resultado): void
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT COUNT(*) AS total
                FROM {$consultasExternas}
                WHERE modalidade_pedido = 'pdf_processo'
                  AND status_resultado = 'sucesso'
                  AND (
                      data_validade IS NULL
                      OR data_validade > DATE_ADD(COALESCE(data_resposta, data_consulta, NOW()), INTERVAL ? DAY)
                  )";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('i', $this->pdfRetentionDays);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $resultado['consultas_pdf_validade_ajustada'] = $total;

        if ($dryRun || $total === 0) {
            return;
        }

        $sql = "UPDATE {$consultasExternas}
                SET data_validade = DATE_ADD(COALESCE(data_resposta, data_consulta, NOW()), INTERVAL ? DAY)
                WHERE modalidade_pedido = 'pdf_processo'
                  AND status_resultado = 'sucesso'
                  AND (
                      data_validade IS NULL
                      OR data_validade > DATE_ADD(COALESCE(data_resposta, data_consulta, NOW()), INTERVAL ? DAY)
                  )";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('ii', $this->pdfRetentionDays, $this->pdfRetentionDays);
        $stmt->execute();
        $stmt->close();
    }

    private function limparPdfsLocais(bool $dryRun, array &$resultado): void
    {
        if (!is_dir($this->pdfStorageDir)) {
            return;
        }

        $limite = time() - ($this->pdfRetentionDays * 86400);
        $arquivos = glob(rtrim($this->pdfStorageDir, '/\\') . DIRECTORY_SEPARATOR . '*');

        if (!is_array($arquivos)) {
            return;
        }

        foreach ($arquivos as $path) {
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isMarker = substr($path, -9) === '.sem_capa';

            if ($ext !== 'pdf' && !$isMarker) {
                continue;
            }

            $mtime = filemtime($path);

            if ($mtime === false || $mtime >= $limite) {
                continue;
            }

            if (!$dryRun && !@unlink($path)) {
                $resultado['erros'][] = 'Nao foi possivel remover ' . $path;
                continue;
            }

            if ($isMarker) {
                $resultado['marcadores_removidos']++;
            } else {
                $resultado['pdfs_removidos']++;
            }
        }
    }

    private function expirarConsultasPdfSemArquivo(bool $dryRun, array &$resultado): void
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT id_consulta, link_pdf_cliente
                FROM {$consultasExternas}
                WHERE modalidade_pedido = 'pdf_processo'
                  AND status_resultado = 'sucesso'
                  AND link_pdf_cliente IS NOT NULL";

        $result = $this->mysqli->query($sql);

        if (!$result) {
            $resultado['erros'][] = $this->mysqli->error;
            return;
        }

        $ids = [];

        while ($row = $result->fetch_assoc()) {
            $path = (string) ($row['link_pdf_cliente'] ?? '');

            $resolvedPath = $this->resolverArquivoLocal($path);

            if ($path === '' || $resolvedPath === null || !is_file($resolvedPath)) {
                $ids[] = (int) $row['id_consulta'];
            }
        }

        $result->free();

        if (!$ids) {
            return;
        }

        $resultado['consultas_pdf_sucesso_sem_arquivo'] = count($ids);
        $resultado['consultas_pdf_expiradas'] = count($ids);

        if ($dryRun) {
            return;
        }

        $stmt = $this->mysqli->prepare(
            "UPDATE {$consultasExternas}
             SET link_pdf_cliente = NULL,
                 status_resultado = 'expirado',
                 mensagem_resultado = 'PDF local não encontrado pela rotina de limpeza.',
                 updated_at = NOW()
             WHERE id_consulta = ?"
        );

        foreach ($ids as $id) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
        }

        $stmt->close();
    }

    private function compactarJsonVencido(bool $dryRun, array &$resultado): void
    {
        $consultasExternas = consulta_table('consultas_externas');
        $sql = "SELECT id_consulta, modalidade_pedido, entrada_normalizada, fornecedor, status_resultado,
                       mensagem_resultado, data_consulta, data_resposta, data_validade,
                       creditos_externo_consumido, link_original_pdf, link_expira_em
                FROM {$consultasExternas}
                WHERE dados_json IS NOT NULL
                  AND CHAR_LENGTH(dados_json) > 20000
                  AND data_resposta IS NOT NULL
                  AND data_resposta < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND (data_validade IS NULL OR data_validade < NOW())";

        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('i', $this->jsonCompactDays);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $resultado['consultas_json_compactadas'] = count($rows);

        if ($dryRun || !$rows) {
            return;
        }

        $update = $this->mysqli->prepare(
            "UPDATE {$consultasExternas}
             SET dados_json = ?,
                 mensagem_resultado = CONCAT(COALESCE(mensagem_resultado, ''), ' JSON pesado compactado pela rotina de limpeza.'),
                 updated_at = NOW()
             WHERE id_consulta = ?"
        );

        foreach ($rows as $row) {
            $compactado = json_encode([
                'compactado' => true,
                'compactado_em' => date('c'),
                'motivo' => 'Payload vencido compactado para reduzir uso de banco.',
                'modalidade_pedido' => $row['modalidade_pedido'],
                'entrada_normalizada' => $row['entrada_normalizada'],
                'fornecedor' => $row['fornecedor'],
                'status_resultado' => $row['status_resultado'],
                'mensagem_resultado' => $row['mensagem_resultado'],
                'data_consulta' => $row['data_consulta'],
                'data_resposta' => $row['data_resposta'],
                'data_validade' => $row['data_validade'],
                'creditos_externo_consumido' => $row['creditos_externo_consumido'],
                'link_original_pdf_presente' => !empty($row['link_original_pdf']),
                'link_expira_em' => $row['link_expira_em'],
            ], JSON_UNESCAPED_UNICODE);

            $id = (int) $row['id_consulta'];
            $update->bind_param('si', $compactado, $id);
            $update->execute();
        }

        $update->close();
    }

    private function resolverArquivoLocal(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $candidatos = [$path];
        $basename = basename(str_replace('\\', '/', $path));

        if ($basename !== '') {
            $candidatos[] = rtrim($this->pdfStorageDir, '/\\') . DIRECTORY_SEPARATOR . $basename;
        }

        if (str_starts_with($path, '/var/www/html/storage/')) {
            $relative = substr($path, strlen('/var/www/html/storage/'));
            $candidatos[] = dirname(__DIR__) . '/storage/' . $relative;
        }

        if (str_starts_with($path, 'storage/') || str_starts_with($path, 'storage\\')) {
            $candidatos[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . $path;
        }

        foreach (array_unique($candidatos) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

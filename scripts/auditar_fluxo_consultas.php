<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../database/consulta_tables.php';

$dias = max(1, (int) ($argv[1] ?? 30));

function audit_db(): mysqli
{
    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) bidmap_env('DB_CONNECT_TIMEOUT', '5')));
    $mysqli->real_connect(
        bidmap_env('DB_HOST', '127.0.0.1'),
        bidmap_env('DB_USER', 'root'),
        bidmap_env('DB_PASSWORD', ''),
        bidmap_env('DB_DATABASE', 'bidmap_dev'),
        (int) bidmap_env('DB_PORT', '3306')
    );

    if ($mysqli->connect_error) {
        throw new RuntimeException('Erro ao conectar no banco: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function audit_rows(mysqli $mysqli, string $sql, string $types = '', array $params = []): array
{
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar auditoria: ' . $mysqli->error);
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function audit_status(array $rows): string
{
    return $rows === [] ? 'ok' : 'atenção';
}

try {
    $mysqli = audit_db();
    $pedidos = consulta_table('pedidos_usuarios');
    $jobs = consulta_table('jobs');
    $consultas = consulta_table('consultas_externas');
    $externo = consulta_table('controle_credito_externo');

    $inicio = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'));
    $primeiroJobRows = audit_rows($mysqli, "SELECT MIN(created_at) AS primeiro_job FROM {$jobs}");
    $inicioJobs = (string) ($primeiroJobRows[0]['primeiro_job'] ?? '');
    $inicioArquiteturaJobs = $inicioJobs !== '' && strtotime($inicioJobs) !== false && strtotime($inicioJobs) > strtotime($inicio)
        ? $inicioJobs
        : $inicio;
    $checks = [];

    $checks['debitos_externos_duplicados'] = audit_rows(
        $mysqli,
        "SELECT id_consulta, fornecedor, COUNT(*) AS total, SUM(creditos_movimentados) AS creditos
         FROM {$externo}
         WHERE operacao = 'debito'
           AND id_consulta IS NOT NULL
           AND data_operacao >= ?
         GROUP BY id_consulta, fornecedor
         HAVING COUNT(*) > 1
         ORDER BY total DESC, id_consulta DESC",
        's',
        [$inicio]
    );

    $checks['jobs_duplicados_por_pedido_tipo'] = audit_rows(
        $mysqli,
        "SELECT id_consulta_usuario, tipo_job, COUNT(*) AS total, GROUP_CONCAT(id_job ORDER BY id_job) AS jobs
         FROM {$jobs}
         WHERE id_consulta_usuario IS NOT NULL
           AND created_at >= ?
         GROUP BY id_consulta_usuario, tipo_job
         HAVING COUNT(*) > 1
         ORDER BY total DESC, id_consulta_usuario DESC",
        's',
        [$inicioArquiteturaJobs]
    );

    $checks['pedidos_web_sem_job'] = audit_rows(
        $mysqli,
        "SELECT p.id_consulta_usuario, p.modalidade_pedido, p.entrada_original, p.status_resultado, p.id_consulta, p.data_pedido
         FROM {$pedidos} p
         LEFT JOIN {$jobs} j
           ON j.id_consulta_usuario = p.id_consulta_usuario
          AND j.tipo_job = p.modalidade_pedido
         WHERE p.data_pedido >= ?
           AND COALESCE(p.origem, 'web') <> 'cache'
           AND p.modalidade_pedido IN ('consulta_processos', 'dados_cpf', 'dados_cnpj', 'detalhes_processo', 'pdf_processo')
           AND p.status_resultado <> 'saldo_insuficiente'
           AND j.id_job IS NULL
         ORDER BY p.id_consulta_usuario DESC
         LIMIT 100",
        's',
        [$inicioArquiteturaJobs]
    );

    $checks['jobs_concluidos_sem_consulta'] = audit_rows(
        $mysqli,
        "SELECT id_job, tipo_job, id_consulta_usuario, id_consulta, status_job, created_at, finished_at
         FROM {$jobs}
         WHERE created_at >= ?
           AND status_job = 'completed'
           AND (id_consulta IS NULL OR id_consulta = 0)
         ORDER BY id_job DESC
         LIMIT 100",
        's',
        [$inicio]
    );

    $checks['pedidos_sucesso_sem_consulta'] = audit_rows(
        $mysqli,
        "SELECT id_consulta_usuario, modalidade_pedido, entrada_original, status_resultado, id_consulta, data_pedido
         FROM {$pedidos}
         WHERE data_pedido >= ?
           AND status_resultado = 'sucesso'
           AND (id_consulta IS NULL OR id_consulta = 0)
         ORDER BY id_consulta_usuario DESC
         LIMIT 100",
        's',
        [$inicio]
    );

    $checks['cache_com_credito_ou_custo'] = audit_rows(
        $mysqli,
        "SELECT id_consulta_usuario, modalidade_pedido, entrada_original, origem, status_resultado,
                creditos_usuario_consumidos, credito_externo_consumido, data_pedido
         FROM {$pedidos}
         WHERE data_pedido >= ?
           AND origem = 'cache'
           AND (
                COALESCE(creditos_usuario_consumidos, 0) <> 0
             OR COALESCE(credito_externo_consumido, 0) <> 0
           )
         ORDER BY id_consulta_usuario DESC
         LIMIT 100",
        's',
        [$inicio]
    );

    $checks['pendencias_antigas'] = audit_rows(
        $mysqli,
        "SELECT p.id_consulta_usuario, p.modalidade_pedido, p.entrada_original, p.status_resultado, p.id_consulta,
                p.data_pedido, p.mensagem_resultado, j.id_job, j.status_job, j.tentativas, j.max_tentativas
         FROM {$pedidos} p
         LEFT JOIN {$jobs} j ON j.id_consulta_usuario = p.id_consulta_usuario
         WHERE p.status_resultado = 'pendente'
           AND p.data_pedido < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
         ORDER BY p.data_pedido ASC
         LIMIT 100"
    );

    $checks['jobs_antigos_processando_ou_pendentes'] = audit_rows(
        $mysqli,
        "SELECT id_job, tipo_job, status_job, id_consulta_usuario, id_consulta, tentativas,
                max_tentativas, available_at, created_at, updated_at, last_error
         FROM {$jobs}
         WHERE status_job IN ('pending', 'processing')
           AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
         ORDER BY created_at ASC
         LIMIT 100"
    );

    $checks['pedidos_sucesso_consulta_nao_sucesso'] = audit_rows(
        $mysqli,
        "SELECT p.id_consulta_usuario, p.modalidade_pedido, p.entrada_original, p.status_resultado AS pedido_status,
                p.id_consulta, c.status_resultado AS consulta_status, p.data_pedido
         FROM {$pedidos} p
         LEFT JOIN {$consultas} c ON c.id_consulta = p.id_consulta
         WHERE p.data_pedido >= ?
           AND p.status_resultado = 'sucesso'
           AND p.id_consulta IS NOT NULL
           AND COALESCE(c.status_resultado, '') <> 'sucesso'
         ORDER BY p.id_consulta_usuario DESC
         LIMIT 100",
        's',
        [$inicio]
    );

    $mysqli->close();

    $summary = [];
    foreach ($checks as $name => $rows) {
        $summary[$name] = [
            'status' => audit_status($rows),
            'total' => count($rows),
        ];
    }

    echo json_encode([
        'ok' => !array_filter($summary, static fn (array $item): bool => $item['status'] !== 'ok'),
        'periodo_dias' => $dias,
        'inicio' => $inicio,
        'summary' => $summary,
        'details' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

<?php

function bidmap_demo_now(string $modifier = 'now'): string
{
    return (new DateTimeImmutable($modifier))->format('Y-m-d H:i:s');
}

function bidmap_demo_consultas(): array
{
    return [
        [
            'id_consulta_usuario' => 9101,
            'id_consulta' => 9001,
            'id_usuario' => 1,
            'modalidade_pedido' => 'dados_cpf',
            'entrada_original' => '123.456.789-00',
            'entrada_normalizada' => '12345678900',
            'status_resultado' => 'sucesso',
            'mensagem_resultado' => 'Dados cadastrais retornados em modo demo.',
            'dados_json' => json_encode(['nomeCompleto' => 'Pessoa Demo'], JSON_UNESCAPED_UNICODE),
            'data_pedido' => bidmap_demo_now('-35 minutes'),
            'data_validade' => bidmap_demo_now('+30 days'),
            'creditos_usuario_consumidos' => 3.0,
            'credito_externo_consumido' => 0.8,
            'fornecedor' => 'hubdev',
            'origem' => 'web',
            'link_pdf_cliente' => '',
            'pdf_relacionado_consulta_id' => null,
            'detalhe_relacionado_consulta_id' => null,
            'doc_relacionado_consulta_id' => null,
        ],
        [
            'id_consulta_usuario' => 9102,
            'id_consulta' => 9002,
            'id_usuario' => 1,
            'modalidade_pedido' => 'dados_cnpj',
            'entrada_original' => '12.345.678/0001-90',
            'entrada_normalizada' => '12345678000190',
            'status_resultado' => 'sucesso',
            'mensagem_resultado' => 'Dados empresariais retornados em modo demo.',
            'dados_json' => json_encode(['razao_social' => 'Empresa Demo LTDA'], JSON_UNESCAPED_UNICODE),
            'data_pedido' => bidmap_demo_now('-1 hour'),
            'data_validade' => bidmap_demo_now('+30 days'),
            'creditos_usuario_consumidos' => 3.0,
            'credito_externo_consumido' => 1.2,
            'fornecedor' => 'hubdev',
            'origem' => 'web',
            'link_pdf_cliente' => '',
            'pdf_relacionado_consulta_id' => null,
            'detalhe_relacionado_consulta_id' => null,
            'doc_relacionado_consulta_id' => null,
        ],
        [
            'id_consulta_usuario' => 9103,
            'id_consulta' => 9003,
            'id_usuario' => 1,
            'modalidade_pedido' => 'consulta_processos',
            'entrada_original' => '123.456.789-00',
            'entrada_normalizada' => '12345678900',
            'status_resultado' => 'sucesso',
            'mensagem_resultado' => '2 processos encontrados no ambiente demo.',
            'dados_json' => json_encode(['lawsuits' => [['number' => '1000000-00.2024.8.26.0100'], ['number' => '1000001-00.2024.8.26.0100']]], JSON_UNESCAPED_UNICODE),
            'data_pedido' => bidmap_demo_now('-2 hours'),
            'data_validade' => bidmap_demo_now('+30 days'),
            'creditos_usuario_consumidos' => 5.0,
            'credito_externo_consumido' => 2.5,
            'fornecedor' => 'consultaprocesso',
            'origem' => 'web',
            'link_pdf_cliente' => '',
            'pdf_relacionado_consulta_id' => null,
            'detalhe_relacionado_consulta_id' => null,
            'doc_relacionado_consulta_id' => 9001,
        ],
        [
            'id_consulta_usuario' => 9104,
            'id_consulta' => 9004,
            'id_usuario' => 1,
            'modalidade_pedido' => 'detalhes_processo',
            'entrada_original' => '1000000-00.2024.8.26.0100',
            'entrada_normalizada' => '10000000020248260100',
            'status_resultado' => 'sucesso',
            'mensagem_resultado' => 'Detalhes processuais retornados em modo demo.',
            'dados_json' => json_encode(['classe' => 'Procedimento Comum Civel'], JSON_UNESCAPED_UNICODE),
            'data_pedido' => bidmap_demo_now('-3 hours'),
            'data_validade' => bidmap_demo_now('+30 days'),
            'creditos_usuario_consumidos' => 3.0,
            'credito_externo_consumido' => 1.5,
            'fornecedor' => 'consultaprocesso',
            'origem' => 'web',
            'link_pdf_cliente' => '',
            'pdf_relacionado_consulta_id' => 9005,
            'detalhe_relacionado_consulta_id' => null,
            'doc_relacionado_consulta_id' => null,
        ],
        [
            'id_consulta_usuario' => 9105,
            'id_consulta' => 9005,
            'id_usuario' => 1,
            'modalidade_pedido' => 'pdf_processo',
            'entrada_original' => '1000000-00.2024.8.26.0100',
            'entrada_normalizada' => '10000000020248260100',
            'status_resultado' => 'sucesso',
            'mensagem_resultado' => 'PDF demonstrativo disponivel em modo demo.',
            'dados_json' => '{}',
            'data_pedido' => bidmap_demo_now('-4 hours'),
            'data_validade' => bidmap_demo_now('+30 days'),
            'creditos_usuario_consumidos' => 12.0,
            'credito_externo_consumido' => 4.0,
            'fornecedor' => 'processorapido',
            'origem' => 'web',
            'link_pdf_cliente' => '',
            'pdf_relacionado_consulta_id' => null,
            'detalhe_relacionado_consulta_id' => 9004,
            'doc_relacionado_consulta_id' => null,
        ],
    ];
}

function bidmap_demo_extrato(): array
{
    return [
        [
            'evento_id' => 1,
            'tipo' => 'C',
            'valor' => 100.0,
            'descricao' => 'Saldo inicial do modo demo',
            'ativo' => 1,
            'status' => 'confirmado',
            'data_evento' => bidmap_demo_now('-1 day'),
            'idtransacao' => 'DEMO-START',
            'origem' => 'portfolio',
        ],
        [
            'evento_id' => 2,
            'tipo' => 'D',
            'valor' => 5.0,
            'descricao' => 'Busca de processos por CPF em modo demo',
            'ativo' => 1,
            'status' => 'confirmado',
            'data_evento' => bidmap_demo_now('-2 hours'),
            'idtransacao' => 'DEMO-PROC',
            'origem' => 'consulta',
        ],
        [
            'evento_id' => 3,
            'tipo' => 'D',
            'valor' => 12.0,
            'descricao' => 'Download de PDF processual em modo demo',
            'ativo' => 1,
            'status' => 'confirmado',
            'data_evento' => bidmap_demo_now('-4 hours'),
            'idtransacao' => 'DEMO-PDF',
            'origem' => 'consulta',
        ],
        [
            'evento_id' => 4,
            'tipo' => 'C',
            'valor' => 3.0,
            'descricao' => 'Estorno demonstrativo de consulta cancelada',
            'ativo' => 1,
            'status' => 'confirmado',
            'data_evento' => bidmap_demo_now('-6 hours'),
            'idtransacao' => 'DEMO-REFUND',
            'origem' => 'portfolio',
        ],
    ];
}

function bidmap_demo_painel(): array
{
    return [
        'metricas' => [
            'pedidos' => 5,
            'creditos_usuario' => 26.0,
            'creditos_externos' => 10.0,
            'consultas_web' => 5,
        ],
        'historico' => bidmap_demo_consultas(),
        'historicoUsuario' => bidmap_demo_consultas(),
        'tipos' => [
            ['modalidade_pedido' => 'dados_cpf', 'fornecedor' => 'hubdev', 'total' => 1, 'creditos_usuario' => 3.0, 'creditos_externos' => 0.8],
            ['modalidade_pedido' => 'dados_cnpj', 'fornecedor' => 'hubdev', 'total' => 1, 'creditos_usuario' => 3.0, 'creditos_externos' => 1.2],
            ['modalidade_pedido' => 'consulta_processos', 'fornecedor' => 'consultaprocesso', 'total' => 1, 'creditos_usuario' => 5.0, 'creditos_externos' => 2.5],
            ['modalidade_pedido' => 'detalhes_processo', 'fornecedor' => 'consultaprocesso', 'total' => 1, 'creditos_usuario' => 3.0, 'creditos_externos' => 1.5],
            ['modalidade_pedido' => 'pdf_processo', 'fornecedor' => 'processorapido', 'total' => 1, 'creditos_usuario' => 12.0, 'creditos_externos' => 4.0],
        ],
        'fornecedores' => [
            ['fornecedor' => 'hubdev', 'operacao' => 'credito', 'data_operacao' => bidmap_demo_now('-2 days'), 'creditos_movimentados' => 500.0, 'saldo_anterior' => 0.0, 'saldo_final' => 500.0, 'observacao' => 'Carga inicial demo'],
            ['fornecedor' => 'consultaprocesso', 'operacao' => 'debito', 'data_operacao' => bidmap_demo_now('-2 hours'), 'creditos_movimentados' => 4.0, 'saldo_anterior' => 500.0, 'saldo_final' => 496.0, 'observacao' => 'Consumo de consulta demo'],
        ],
        'saldosExternos' => [
            ['fornecedor' => 'hubdev', 'saldo_final' => 499.2],
            ['fornecedor' => 'consultaprocesso', 'saldo_final' => 496.0],
            ['fornecedor' => 'processorapido', 'saldo_final' => 496.0],
        ],
        'custosOperacoes' => [
            ['chave' => 'dados_cpf', 'operacao' => 'Dados CPF', 'modalidade_pedido' => 'dados_cpf', 'fornecedor' => 'hubdev', 'creditos_usuario' => 3.0, 'custo_externo' => 0.8, 'observacao' => 'Demo'],
            ['chave' => 'consulta_processos', 'operacao' => 'Busca de processos', 'modalidade_pedido' => 'consulta_processos', 'fornecedor' => 'consultaprocesso', 'creditos_usuario' => 5.0, 'custo_externo' => 2.5, 'observacao' => 'Demo'],
            ['chave' => 'pdf_processo', 'operacao' => 'PDF do processo', 'modalidade_pedido' => 'pdf_processo', 'fornecedor' => 'processorapido', 'creditos_usuario' => 12.0, 'custo_externo' => 4.0, 'observacao' => 'Demo'],
        ],
    ];
}

function bidmap_demo_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function bidmap_demo_find_consulta(int $consultaId): ?array
{
    foreach (bidmap_demo_consultas() as $consulta) {
        if ((int) ($consulta['id_consulta'] ?? 0) === $consultaId) {
            return $consulta;
        }
    }

    return null;
}

function bidmap_demo_dados_pessoais(string $tipoConsulta, string $entrada = ''): array
{
    $tipoConsulta = strtolower($tipoConsulta) === 'cnpj' ? 'cnpj' : 'cpf';

    if ($tipoConsulta === 'cnpj') {
        return [
            'numero_de_inscricao' => preg_replace('/\D+/', '', $entrada) ?: '12345678000190',
            'nome' => 'BidMap Demo Tecnologia LTDA',
            'fantasia' => 'BidMap Demo',
            'situacao' => 'Ativa',
            'abertura' => '15/03/2021',
            'natureza_juridica' => 'Sociedade Empresaria Limitada',
            'tipo' => 'Matriz',
            'dt_situacao_cadastral' => '15/03/2021',
            'situacao_especial' => '',
            'porte' => 'Empresa de Pequeno Porte',
            'opcao_pelo_simples' => 'Sim',
            'opcao_pelo_mei' => 'Nao',
            'capital_social' => '85000.00',
            'entidade_federativo_responsavel' => '',
            'logradouro' => 'Avenida Paulista',
            'numero' => '1000',
            'bairro' => 'Bela Vista',
            'municipio' => 'Sao Paulo',
            'uf' => 'SP',
            'cep' => '01310-100',
            'complemento' => 'Conjunto 1201',
            'email' => 'contato@bidmapdemo.com.br',
            'telefone' => '(11) 3000-0000',
            'atividade_principal' => [
                'code' => '62.01-5-01',
                'text' => 'Desenvolvimento de programas de computador sob encomenda',
            ],
            'atividades_secundarias' => [
                ['code' => '62.04-0-00', 'text' => 'Consultoria em tecnologia da informacao'],
                ['code' => '63.19-4-00', 'text' => 'Portais, provedores de conteudo e outros servicos de informacao'],
            ],
            'quadro_socios' => [
                ['nome' => 'Patrick Demo', 'qualificacao' => 'Socio administrador'],
                ['nome' => 'Gabriela Demo', 'qualificacao' => 'Socia'],
            ],
        ];
    }

    return [
        'documento' => preg_replace('/\D+/', '', $entrada) ?: '12345678900',
        'codigoPessoa' => 'DEMO-102938',
        'nomeCompleto' => 'Patrick Demo',
        'genero' => 'Masculino',
        'dataDeNascimento' => '12/05/1999',
        'anos' => 27,
        'zodiaco' => 'Touro',
        'nomeDaMae' => 'Maria Demo',
        'statusCadastral' => 'Regular',
        'dataStatusCadastral' => '10/01/2026',
        'salarioEstimado' => '4800.00',
        'lastUpdate' => '25/06/2026',
        'listaTelefones' => [
            [
                'telefoneComDDD' => '(11) 99999-0000',
                'tipoTelefone' => 'Celular',
                'operadora' => 'Vivo',
                'whatsApp' => 'Sim',
                'telemarketingBloqueado' => 'Nao',
            ],
            [
                'telefoneComDDD' => '(11) 3333-0000',
                'tipoTelefone' => 'Fixo',
                'operadora' => 'Claro',
                'whatsApp' => 'Nao',
                'telemarketingBloqueado' => 'Sim',
            ],
        ],
        'listaEmails' => [
            ['enderecoEmail' => 'patrick.demo@email.com'],
            ['enderecoEmail' => 'portfolio@bidmapdemo.com.br'],
        ],
        'listaEnderecos' => [
            [
                'logradouro' => 'Rua Demo',
                'numero' => '123',
                'bairro' => 'Centro',
                'cidade' => 'Sao Paulo',
                'uf' => 'SP',
                'complemento' => 'Apto 45',
                'cep' => '01000-000',
            ],
            [
                'logradouro' => 'Avenida Portfolio',
                'numero' => '456',
                'bairro' => 'Jardins',
                'cidade' => 'Sao Paulo',
                'uf' => 'SP',
                'complemento' => 'Sala 8',
                'cep' => '01400-000',
            ],
        ],
    ];
}

function bidmap_demo_processos_documento(string $entrada = ''): array
{
    return [
        'nome' => 'Patrick Demo',
        'documento' => preg_replace('/\D+/', '', $entrada) ?: '12345678900',
        'lawsuits' => [
            bidmap_demo_processo_detalhe('1000000-00.2024.8.26.0100') + [
                'documentPolarity' => 'ACTIVE',
            ],
            [
                'number' => '1000001-00.2024.8.26.0100',
                'cnjMasked' => '1000001-00.2024.8.26.0100',
                'courtType' => 'TRABALHISTA',
                'instance' => '1',
                'mainSubject' => 'Rescisao do Contrato de Trabalho',
                'iBroadCNJSubjectName' => 'Direito do Trabalho - Contrato Individual de Trabalho',
                'courtName' => 'TRT 2 Regiao',
                'courtDistrict' => 'Sao Paulo',
                'state' => 'SP',
                'author' => 'Patrick Demo',
                'reu' => 'Empresa Demo LTDA',
                'documentPolarity' => 'ACTIVE',
                'lastMovementDate' => '2026-06-18 14:20:00',
                'value' => '18500.00',
            ],
            [
                'number' => '2000000-55.2023.8.19.0001',
                'cnjMasked' => '2000000-55.2023.8.19.0001',
                'courtType' => 'CIVEL',
                'instance' => '2',
                'mainSubject' => 'Obrigacoes',
                'iBroadCNJSubjectName' => 'Direito Civil - Obrigacoes',
                'courtName' => 'TJRJ',
                'courtDistrict' => 'Rio de Janeiro',
                'state' => 'RJ',
                'author' => 'Banco Demo S.A.',
                'reu' => 'Patrick Demo',
                'documentPolarity' => 'PASSIVE',
                'lastMovementDate' => '2026-05-28 09:10:00',
                'value' => '42000.00',
            ],
        ],
    ];
}

function bidmap_demo_processo_detalhe(string $numero = '1000000-00.2024.8.26.0100'): array
{
    return [
        'number' => $numero,
        'cnjMasked' => $numero,
        'cnjOnlyNumbers' => preg_replace('/\D+/', '', $numero),
        'titulo' => 'Patrick Demo x Empresa Demo LTDA',
        'author' => 'Patrick Demo',
        'reu' => 'Empresa Demo LTDA',
        'courtType' => 'CIVEL',
        'lawsuitType' => 'Procedimento Comum Civel',
        'className' => 'Procedimento Comum Civel',
        'procedureName' => 'Procedimento Comum Civel',
        'courtName' => 'TJSP',
        'courtDistrict' => 'Foro Central Civel - Sao Paulo',
        'state' => 'SP',
        'jurisdiction' => 'Justica Estadual',
        'judge' => 'Dra. Ana Demo',
        'forum' => 'Foro Central Civel',
        'mainSubject' => 'Responsabilidade Civil',
        'iCNJSubjectName' => 'Indenizacao por Dano Material',
        'iBroadCNJSubjectName' => 'Direito Civil - Responsabilidade Civil - Indenizacao por Dano Material',
        'iCNJSubjectNumber' => '10433',
        'iBroadCNJSubjectNumber' => '899',
        'lawArea' => 'Direito Civil',
        'value' => '35000.00',
        'distributionDate' => '2024-02-20',
        'noticeDate' => '2024-02-22',
        'lastMovementDate' => '2026-06-20 16:45:00',
        'captureDate' => '2026-06-25 10:30:00',
        'statusName' => 'Em andamento',
        'instance' => '1',
        'numberOfParties' => 4,
        'numberOfUpdates' => 6,
        'lawSuitAge' => '2 anos e 4 meses',
        'averageNumberOfUpdatesPerMonth' => '1.8',
        'parties' => [
            ['name' => 'Patrick Demo', 'specificType' => 'Autor', 'document' => '123.456.789-00'],
            ['name' => 'Empresa Demo LTDA', 'specificType' => 'Reu', 'document' => '12.345.678/0001-90'],
            ['name' => 'Dra. Camila Advocacia', 'specificType' => 'Advogada do autor', 'oab' => 'OAB/SP 000000'],
            ['name' => 'Dr. Roberto Defesa', 'specificType' => 'Advogado do reu', 'oab' => 'OAB/SP 111111'],
        ],
        'movements' => [
            ['date' => '2026-06-20', 'title' => 'Juntada de peticao', 'description' => 'Peticao intermediaria protocolada pela parte autora.', 'source' => 'TJSP'],
            ['date' => '2026-05-12', 'title' => 'Conclusos para decisao', 'description' => 'Processo encaminhado ao gabinete para analise.', 'source' => 'TJSP'],
            ['date' => '2026-04-03', 'title' => 'Manifestacao da parte requerida', 'description' => 'Apresentada contestacao com documentos.', 'source' => 'TJSP'],
            ['date' => '2026-03-15', 'title' => 'Audiencia designada', 'description' => 'Audiencia de conciliacao marcada em ambiente virtual.', 'source' => 'TJSP'],
            ['date' => '2025-11-08', 'title' => 'Citada a parte requerida', 'description' => 'Ciencia confirmada no portal eletronico.', 'source' => 'TJSP'],
            ['date' => '2024-02-22', 'title' => 'Distribuido por sorteio', 'description' => 'Processo distribuido ao juizo competente.', 'source' => 'TJSP'],
        ],
        'documents' => [
            ['name' => 'Peticao inicial', 'format' => 'PDF', 'date' => '2024-02-20', 'url' => '#'],
            ['name' => 'Decisao interlocutoria', 'format' => 'PDF', 'date' => '2026-05-12', 'url' => '#'],
            ['name' => 'Certidao de publicacao', 'format' => 'PDF', 'date' => '2026-06-20', 'url' => '#'],
        ],
    ];
}

function bidmap_demo_render_page(string $title, string $subtitle, array $rows, string $backUrl = 'consultar_processos.php#historico-consultas'): void
{
    if (!defined('BIDMAP_HEADER_ASSETS_LOADED')) {
        define('BIDMAP_HEADER_ASSETS_LOADED', true);
    }

    echo '<!doctype html><html lang="pt-br"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . bidmap_demo_h($title) . '</title>';
    echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="css/style.css?v=' . filemtime(__DIR__ . '/../css/style.css') . '">';
    echo '<link rel="stylesheet" href="css/header.css?v=' . filemtime(__DIR__ . '/../css/header.css') . '">';
    echo '<link rel="stylesheet" href="css/consultar_processos.css?v=' . filemtime(__DIR__ . '/../css/consultar_processos.css') . '">';
    echo '<script src="js/header.js?v=' . filemtime(__DIR__ . '/../js/header.js') . '"></script>';
    echo '</head><body><div id="main">';
    include __DIR__ . '/../header/header.php';
    echo '<main class="processos-page">';
    echo '<section class="processes-card" style="max-width: 980px; margin: 48px auto;">';
    echo '<div class="section-title-row"><div><h1>' . bidmap_demo_h($title) . '</h1><p>' . bidmap_demo_h($subtitle) . '</p></div>';
    echo '<a class="ghost-btn" href="' . bidmap_demo_h($backUrl) . '">Voltar</a></div>';
    echo '<div class="history-list" style="margin-top: 24px;">';

    foreach ($rows as $label => $value) {
        echo '<article class="history-item"><div class="history-main">';
        echo '<span class="history-type">' . bidmap_demo_h((string) $label) . '</span>';
        echo '<strong>' . bidmap_demo_h((string) $value) . '</strong>';
        echo '</div></article>';
    }

    echo '</div>';
    echo '<div class="empty-state" style="margin-top: 24px;"><span>Dados ficticios para avaliacao local. Nenhuma API externa foi acionada.</span></div>';
    echo '</section></main></div></body></html>';
    exit;
}

function bidmap_demo_output_pdf(): void
{
    $objects = [
        '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
        '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
        '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >> endobj',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object . "\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="processo-demo-em-branco.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

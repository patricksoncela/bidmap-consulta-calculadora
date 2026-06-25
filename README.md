# Ferramenta de Consulta e Calculadora

Versao limpa para portfolio de um modulo web desenvolvido para apoiar analise de leiloes e consultas operacionais.

## Visao Geral

Este recorte reune duas entregas principais:

- Ferramenta de consultas para CPF, CNPJ, processos, detalhes processuais e documentos.
- Calculadora de viabilidade para simular custos, receitas, financiamento, rentabilidade e fluxo de caixa.

O objetivo do projeto foi transformar processos manuais em uma experiencia web mais organizada, com historico de pedidos, controle de creditos, integracao com APIs externas e processamento assincrono para tarefas demoradas.

## Funcionalidades

- Consulta processual por documento ou CNJ.
- Tela de detalhes do processo com partes, movimentacoes e documentos.
- Historico de consultas com filtros, status e paginacao.
- Download seguro de PDFs com token temporario.
- Controle de creditos e painel de extrato.
- Filas com Redis para desacoplar a interface do processamento.
- Workers em PHP e Python para consumir jobs de processos, PDFs e dados cadastrais.
- Calculadora de investimento com cenarios, custos de aquisicao/venda, receitas recorrentes e graficos.
- Exportacao/visualizacao de tabelas completas do calculo.

## Tecnologias

- PHP
- JavaScript
- HTML e CSS
- MySQL
- Redis
- Python
- Docker

## Arquitetura

- `consultar_processos.php`: interface principal de consultas.
- `detalhe_processo.php`: visualizacao detalhada de processo.
- `historico_consultas.php`: historico e filtros.
- `calculadora.php`: calculadora principal.
- `api/`: endpoints de status, fila, PDF e dados pessoais.
- `services/`: regras de negocio e clientes de APIs externas.
- `database/models/`: models de consultas, pedidos, custos, creditos e jobs.
- `scripts/`: rotinas de worker, publicacao, manutencao e diagnostico.
- `workers/python/`: worker Python experimental.
- `docker/`: exemplos de ambiente para Redis e workers.

## Configuracao Local

Copie `.env.example` para `.env` e preencha as credenciais do ambiente local.

```bash
cp .env.example .env
```

Para avaliar apenas a calculadora sem login, mantenha:

```env
CALCULADORA_REQUIRE_AUTH=false
```

As consultas completas dependem de banco MySQL, Redis e chaves de APIs externas.

## Observacao

Esta pasta foi preparada para portfolio: credenciais reais, arquivos `.env`, PDFs gerados, logs, caches e paginas fora do escopo foram removidos. Os valores sensiveis foram substituidos por placeholders em `.env.example`.

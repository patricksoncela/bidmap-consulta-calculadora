# BidMap Consulta e Calculadora

![PHP](https://img.shields.io/badge/PHP-Backend-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-Queues-DC382D?logo=redis&logoColor=white)
![Python](https://img.shields.io/badge/Python-Worker-3776AB?logo=python&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white)

Ferramenta web em PHP para consultas processuais, controle de creditos, filas assincronas com Redis e calculadora de viabilidade para analise de leiloes.

## Resumo

Este repositorio e uma versao limpa para portfolio de um modulo desenvolvido para apoiar usuarios em duas frentes:

- consulta de processos, documentos, CPF/CNPJ, historico de pedidos e controle de creditos;
- calculadora de viabilidade com custos, receitas, financiamento, rentabilidade, fluxo de caixa e tabelas detalhadas.

O projeto combina interface web, endpoints internos, models de banco, services para integracoes externas, jobs assincronos e workers em PHP/Python.

## Minha Participacao

Atuei na evolucao da ferramenta trabalhando em:

- organizacao do backend em `services`, `models`, endpoints e scripts operacionais;
- integracao com APIs externas para processos, documentos e dados cadastrais;
- controle de creditos, historico de pedidos e painel de extrato;
- fluxo assincrono com Redis para processar consultas demoradas fora da requisicao principal;
- workers em PHP e Python para consumir filas;
- geracao e download seguro de documentos com token temporario;
- melhorias na interface da consulta, detalhe do processo, historico e calculadora;
- preparacao de ambiente com `.env.example`, Docker e limpeza de dados sensiveis para portfolio.

## Funcionalidades

- Consulta processual por CPF, CNPJ ou CNJ.
- Tela de detalhes do processo com partes, movimentacoes e documentos.
- Historico de consultas com filtros, status e paginacao.
- Download seguro de PDFs com assinatura temporaria.
- Controle de creditos e painel de extrato.
- Filas com Redis para desacoplar a interface do processamento.
- Workers em PHP e Python para processar jobs de processos, PDFs e dados cadastrais.
- Calculadora de investimento com cenarios, custos de aquisicao/venda, receitas recorrentes e graficos.
- Visualizacao de tabelas completas do calculo.

## Screenshots

As imagens do projeto podem ser adicionadas em `docs/screenshots/`.

Sugestao de arquivos:

- `docs/screenshots/calculadora.png`
- `docs/screenshots/resultado-calculadora.png`
- `docs/screenshots/consulta-processos.png`
- `docs/screenshots/historico-consultas.png`
- `docs/screenshots/detalhe-processo.png`

Depois de capturar as imagens, substitua esta lista por previews em Markdown:

```md
![Calculadora](docs/screenshots/calculadora.png)
![Consulta de Processos](docs/screenshots/consulta-processos.png)
```

## Stack

- PHP
- JavaScript
- HTML e CSS
- MySQL
- Redis
- Python
- Docker

## Estrutura

```text
.
|-- consultar_processos.php
|-- detalhe_processo.php
|-- historico_consultas.php
|-- calculadora.php
|-- api/
|-- services/
|-- database/
|-- scripts/
|-- workers/python/
|-- docker/
|-- css/
|-- js/
`-- img/
```

## Arquitetura

- `consultar_processos.php`: interface principal de consultas.
- `detalhe_processo.php`: visualizacao detalhada de um processo.
- `historico_consultas.php`: historico, filtros e status dos pedidos.
- `calculadora.php`: calculadora principal de viabilidade.
- `api/`: endpoints de status, fila, PDF e dados pessoais.
- `services/`: regras de negocio e clientes de APIs externas.
- `database/models/`: models de consultas, pedidos, custos, creditos e jobs.
- `scripts/`: rotinas de worker, publicacao, manutencao e diagnostico.
- `workers/python/`: worker Python experimental.
- `docker/`: exemplos de ambiente para Redis e workers.

## Como Rodar Localmente

Clone o repositorio:

```bash
git clone https://github.com/patricksoncela/bidmap-consulta-calculadora.git
cd bidmap-consulta-calculadora
```

Crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Para avaliar apenas a calculadora sem login, mantenha:

```env
BIDMAP_PORTFOLIO_DEMO=true
CALCULADORA_REQUIRE_AUTH=false
```

Inicie um servidor PHP local:

```bash
php -S localhost:8000
```

No Windows, voce tambem pode usar o script pronto:

```bat
iniciar_servidor_portfolio.bat
```

Esse script inicia o servidor na pasta correta do projeto, cria `.env` a partir de `.env.example` se necessario e abre a ferramenta no navegador.

Se a porta `8000` ja estiver ocupada por outro servidor, execute:

```bat
parar_servidor_portfolio.bat
```

Depois rode `iniciar_servidor_portfolio.bat` novamente.

Depois acesse:

```text
http://localhost:8000/calculadora.php
```

Para abrir a ferramenta de consultas em modo portfolio:

```text
http://localhost:8000/consultar_processos.php
```

No modo demo, a aplicacao cria uma sessao ficticia, usa saldo de teste, remove navegacao para producao e evita chamadas reais para checkout, login e APIs externas.

As consultas completas em modo real dependem de MySQL, Redis e chaves de APIs externas configuradas no `.env`.

## Workers e Filas

O projeto inclui dois caminhos de processamento assincrono:

- worker PHP em `scripts/processar_jobs_redis.php`;
- worker Python em `workers/python/worker.py`.

Os exemplos de Docker em `docker/` mostram como estruturar Redis e workers em ambiente isolado.

## Seguranca e Portfolio

Esta versao foi preparada para publicacao:

- credenciais reais removidas;
- arquivos `.env` reais ignorados;
- PDFs, logs e caches removidos;
- tokens e hosts sensiveis substituidos por placeholders;
- variaveis de ambiente documentadas em `.env.example`.

## Status

Projeto publicado como portfolio tecnico. A calculadora pode ser avaliada localmente sem login; os fluxos completos de consulta exigem banco, Redis e credenciais de APIs externas.

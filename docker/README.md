# Docker

Esta pasta concentra os arquivos Docker da ferramenta de consultas.

## Estrutura

```text
docker/
  compose.worker.yml
  env/
    worker.env.example
    worker.env
  worker/
    Dockerfile
```

## Arquivos

- `compose.worker.yml`: sobe Redis, publisher e worker Redis.
- `worker/Dockerfile`: imagem PHP CLI usada pelo worker.
- `env/worker.env.example`: modelo de configuracao.
- `env/worker.env`: arquivo local/producao com credenciais reais. Nao deve ser versionado.
- `../.env.example`: modelo do env usado pelo PHP web quando a ferramenta roda fora do Docker.

O arquivo `.dockerignore` fica na raiz do projeto por exigencia do Docker, ja que o contexto de build e a pasta `dev2.1`.

## Onde Fica O Env

O PHP web carrega variaveis nesta ordem:

1. `dev2.1/.env`
2. `.env` na raiz acima de `dev2.1`
3. variaveis do ambiente do servidor

O Docker dos workers nao usa automaticamente `dev2.1/.env`; ele usa o arquivo definido no compose:

- desenvolvimento: `dev2.1/docker/env/worker.env`
- producao/VPS: `/opt/bidmap-consultas/env/worker.env`

Os arquivos reais `.env` e `worker.env` nunca devem ser enviados para Git ou pacote publico.

## Primeiro uso local

Na raiz `dev2.1`:

```powershell
Copy-Item docker\env\worker.env.example docker\env\worker.env
```

Preencha `docker/env/worker.env` com os valores reais de MySQL, HubDev, ConsultaProcessos e Processo Rapido.

Validar as variaveis do worker:

```powershell
php scripts\validar_env_consultas.php docker\env\worker.env
```

Validar:

```powershell
docker compose -f docker/compose.worker.yml config
```

Rodar um ciclo unico:

```powershell
docker compose -f docker/compose.worker.yml run --rm job-publisher php scripts/publicar_jobs_redis.php 100
docker compose -f docker/compose.worker.yml run --rm redis-worker php scripts/processar_jobs_redis.php 10 5
```

Rodar em loop:

```powershell
docker compose -f docker/compose.worker.yml up
```

O compose usa uma configuracao conservadora para proteger o banco: um publisher e um worker Redis.

Rodar em segundo plano:

```powershell
docker compose -f docker/compose.worker.yml up -d
docker compose -f docker/compose.worker.yml logs -f redis-worker
```

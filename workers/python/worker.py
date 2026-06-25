from __future__ import annotations

import json
import os
import socket
import sys
import time
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal
from pathlib import Path
from typing import Any, Iterable

import pymysql
import pymysql.cursors
import redis
import requests


TERMINAL_EXTERNAL_STATUSES = {"error", "timeout", "blocked"}
PENDING_EXTERNAL_STATUSES = {"fetching", "pending", "running", "processing"}


def env(name: str, default: str = "") -> str:
    value = os.getenv(name)
    return default if value is None else value


def now_iso() -> str:
    return datetime.now().astimezone().isoformat(timespec="seconds")


def log(message: str) -> None:
    print(f"[{now_iso()}] {message}", flush=True)


def only_digits(value: str) -> str:
    return "".join(ch for ch in value if ch.isdigit())


def format_cnj(value: str) -> str:
    digits = only_digits(value)
    if len(digits) != 20:
        return digits

    return (
        f"{digits[0:7]}-{digits[7:9]}."
        f"{digits[9:13]}.{digits[13:14]}."
        f"{digits[14:16]}.{digits[16:20]}"
    )


def json_dumps(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def decode_json(value: Any) -> dict[str, Any]:
    if not value:
        return {}
    if isinstance(value, dict):
        return value
    try:
        decoded = json.loads(value)
    except (TypeError, json.JSONDecodeError):
        return {}
    return decoded if isinstance(decoded, dict) else {}


def retry_seconds(attempt: int) -> int:
    attempt = max(1, attempt)
    if attempt <= 2:
        return 30
    if attempt <= 4:
        return 60
    if attempt <= 6:
        return 300
    return 600


@dataclass(frozen=True)
class Settings:
    db_host: str = env("DB_HOST", "127.0.0.1")
    db_port: int = int(env("DB_PORT", "3306"))
    db_user: str = env("DB_USER", "root")
    db_password: str = env("DB_PASSWORD", "")
    db_database: str = env("DB_DATABASE", "bidmap_dev")
    db_connect_timeout: int = int(env("DB_CONNECT_TIMEOUT", "5"))
    table_prefix: str = env("CONSULTA_DB_PREFIX", "ferramenta_consulta_")

    redis_host: str = env("REDIS_HOST", "redis")
    redis_port: int = int(env("REDIS_PORT", "6379"))
    redis_db: int = int(env("REDIS_DB", "0"))
    redis_password: str = env("REDIS_PASSWORD", "")
    redis_queue_prefix: str = env("REDIS_QUEUE_PREFIX", "")
    redis_timeout: float = float(env("REDIS_TIMEOUT", "2.5"))

    queues: tuple[str, ...] = tuple(
        q.strip()
        for q in env("PYTHON_WORKER_QUEUES", "consultas:processos,consultas:pdf,consultas:pessoas,consultas:empresas").split(",")
        if q.strip()
    )
    supported_types: tuple[str, ...] = tuple(
        t.strip()
        for t in env("PYTHON_WORKER_SUPPORTED_TYPES", "detalhes_processo,consulta_processos,pdf_processo,dados_cpf,dados_cnpj").split(",")
        if t.strip()
    )
    worker_timeout: int = int(env("PYTHON_WORKER_TIMEOUT", "5"))
    max_jobs: int = int(env("PYTHON_WORKER_MAX_JOBS", "500"))
    max_runtime: int = int(env("PYTHON_WORKER_MAX_RUNTIME", "3600"))

    consulta_api_key: str = env("CONSULTA_PROCESSOS_API_KEY", "")
    consulta_base_url: str = env("CONSULTA_PROCESSOS_BASE_URL", "https://consultadeprocessos.com.br").rstrip("/")
    consulta_document_create_endpoint: str = env("CONSULTA_PROCESSOS_DOCUMENT_CREATE_ENDPOINT", "/api/partner/v2/lawsuits/document")
    consulta_document_result_endpoint: str = env("CONSULTA_PROCESSOS_DOCUMENT_RESULT_ENDPOINT", "/api/partner/v2/lawsuits/document/{requestId}")
    consulta_cnj_endpoint: str = env("CONSULTA_PROCESSOS_CNJ_ENDPOINT", "/api/partner/v2/lawsuits/cnj")
    consulta_parties_create_endpoint: str = env("CONSULTA_PROCESSOS_PARTIES_CREATE_ENDPOINT", "/api/partner/v2/lawsuits/parties")
    consulta_parties_result_endpoint: str = env("CONSULTA_PROCESSOS_PARTIES_RESULT_ENDPOINT", "/api/partner/v2/lawsuits/parties/{requestId}")

    hubdev_base_url: str = env("HUBDEV_BASE_URL", "https://ws.hubdodesenvolvedor.com.br/v2").rstrip("/")
    hubdev_token: str = env("HUBDEV_TOKEN", "")
    hubdev_contract: str = env("HUBDEV_CONTRACT", "")

    processo_rapido_base_url: str = env("PROCESSO_RAPIDO_BASE_URL", "https://api.processorapido.com").rstrip("/")
    processo_rapido_api_key: str = env("PROCESSO_RAPIDO_API_KEY", "")
    processo_rapido_storage_dir: str = env("PROCESSO_RAPIDO_STORAGE_DIR", "storage/processos_pdf")
    processo_rapido_pdf_retention_days: int = int(env("PROCESSO_RAPIDO_PDF_RETENTION_DAYS", "30"))

    credit_api_base_url: str = env("BIDMAP_USUARIOS_API_BASE_URL", "http://localhost:8000/api/usuarios").rstrip("/")
    credit_api_token: str = env("BIDMAP_USUARIOS_API_TOKEN", "")
    credit_api_auth_prefix: str = env("BIDMAP_USUARIOS_API_AUTH_PREFIX", "token")
    credit_api_host_header: str = env("BIDMAP_USUARIOS_API_HOST_HEADER", "")
    credit_api_timeout: int = int(env("BIDMAP_CREDITOS_API_TIMEOUT", "20"))
    credit_api_connect_timeout: int = int(env("BIDMAP_CREDITOS_API_CONNECT_TIMEOUT", "3"))

    http_timeout: int = int(env("PYTHON_WORKER_HTTP_TIMEOUT", "60"))
    unsupported_retry_seconds: int = int(env("PYTHON_WORKER_UNSUPPORTED_RETRY_SECONDS", "900"))

    def table(self, suffix: str) -> str:
        return f"{self.table_prefix}{suffix}"

    def redis_key(self, queue: str) -> str:
        prefix = self.redis_queue_prefix.strip(":")
        return f"{prefix}:{queue.lstrip(':')}" if prefix else queue

    def redis_queue_name(self, raw_key: bytes | str) -> str:
        value = raw_key.decode() if isinstance(raw_key, bytes) else raw_key
        prefix = self.redis_queue_prefix.strip(":")
        if prefix and value.startswith(prefix + ":"):
            return value[len(prefix) + 1 :]
        return value


class PythonConsultaWorker:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.worker_id = f"{socket.gethostname()}:python-worker:{os.getpid()}"
        self.redis = redis.Redis(
            host=settings.redis_host,
            port=settings.redis_port,
            db=settings.redis_db,
            password=settings.redis_password or None,
            socket_connect_timeout=settings.redis_timeout,
            socket_timeout=max(settings.redis_timeout, settings.worker_timeout + 5),
            decode_responses=False,
        )

    def resolve_storage_dir(self) -> Path:
        storage = Path(self.settings.processo_rapido_storage_dir)
        if storage.is_absolute():
            return storage

        php_root = Path("/var/www/html")
        if php_root.exists():
            return php_root / storage

        return Path.cwd() / storage

    @contextmanager
    def db(self):
        conn = pymysql.connect(
            host=self.settings.db_host,
            port=self.settings.db_port,
            user=self.settings.db_user,
            password=self.settings.db_password,
            database=self.settings.db_database,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False,
            connect_timeout=self.settings.db_connect_timeout,
            read_timeout=60,
            write_timeout=60,
        )
        try:
            yield conn
        finally:
            conn.close()

    def ping(self) -> bool:
        self.redis.ping()
        with self.db() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT 1 AS ok")
                row = cur.fetchone()
        return bool(row and row.get("ok") == 1)

    def run_loop(self) -> int:
        started = time.monotonic()
        processed = 0
        log(
            "Loop do worker Python iniciado. "
            f"queues={','.join(self.settings.queues)} "
            f"supported={','.join(self.settings.supported_types)}"
        )

        while processed < self.settings.max_jobs and time.monotonic() - started < self.settings.max_runtime:
            result = self.process_next()
            if not result["processed"]:
                log("Redis sem jobs no intervalo.")
                continue

            processed += 1
            log(
                f"job={result.get('id_job', 0)} "
                f"status={result.get('status', 'unknown')} "
                f"{result.get('message', '')}"
            )

        log(f"Worker Python encerrado. processados={processed}")
        return 0

    def process_next(self) -> dict[str, Any]:
        queue_keys = [self.settings.redis_key(q) for q in self.settings.queues]
        message = self.redis.brpop(queue_keys, timeout=self.settings.worker_timeout)
        if message is None:
            return {"processed": False}

        raw_queue, raw_value = message
        queue = self.settings.redis_queue_name(raw_queue)
        try:
            id_job = int(raw_value.decode() if isinstance(raw_value, bytes) else raw_value)
        except ValueError:
            return {"processed": True, "status": "erro", "message": f"Mensagem Redis invalida na fila {queue}."}

        with self.db() as conn:
            job = self.start_job(conn, id_job)
            if job is None:
                conn.commit()
                return {"processed": True, "id_job": id_job, "status": "ignorado", "message": "Job nao processavel."}

            try:
                if job["tipo_job"] not in self.settings.supported_types:
                    self.requeue_unsupported(conn, job)
                    conn.commit()
                    return {
                        "processed": True,
                        "id_job": id_job,
                        "status": "reagendado",
                        "message": f"Tipo {job['tipo_job']} nao habilitado no worker Python.",
                    }

                result = self.process_job(conn, job)
                self.finish_job(conn, job, result)
                conn.commit()
                return {
                    "processed": True,
                    "id_job": id_job,
                    "status": "pendente" if result.get("pending") else ("ok" if result.get("ok") else "erro"),
                    "message": str(result.get("message", "")),
                }
            except Exception as exc:  # noqa: BLE001
                conn.rollback()
                with self.db() as error_conn:
                    self.mark_job_error(error_conn, id_job, str(exc), {"exception": type(exc).__name__}, retry_seconds(int(job.get("tentativas") or 1)))
                    error_conn.commit()
                return {"processed": True, "id_job": id_job, "status": "erro", "message": str(exc)}

    def start_job(self, conn, id_job: int) -> dict[str, Any] | None:
        jobs = self.settings.table("jobs")
        with conn.cursor() as cur:
            cur.execute(f"SELECT * FROM {jobs} WHERE id_job = %s LIMIT 1 FOR UPDATE", (id_job,))
            job = cur.fetchone()
            if not job or job.get("status_job") != "pending":
                return None

            if int(job.get("tentativas") or 0) >= int(job.get("max_tentativas") or 30):
                cur.execute(
                    f"""
                    UPDATE {jobs}
                       SET status_job = 'failed',
                           last_error = %s,
                           finished_at = NOW(),
                           worker_id = NULL,
                           updated_at = NOW()
                     WHERE id_job = %s
                    """,
                    ("Tentativas maximas do job esgotadas.", id_job),
                )
                return None

            cur.execute(
                f"""
                UPDATE {jobs}
                   SET status_job = 'processing',
                       started_at = COALESCE(started_at, NOW()),
                       last_attempt_at = NOW(),
                       worker_id = %s,
                       tentativas = tentativas + 1,
                       updated_at = NOW()
                 WHERE id_job = %s
                """,
                (self.worker_id, id_job),
            )

        job["tentativas"] = int(job.get("tentativas") or 0) + 1
        job["status_job"] = "processing"
        job["worker_id"] = self.worker_id
        return job

    def requeue_unsupported(self, conn, job: dict[str, Any]) -> None:
        self.mark_job_error(
            conn,
            int(job["id_job"]),
            f"Tipo {job['tipo_job']} nao habilitado no worker Python.",
            {"tipo_job": job["tipo_job"], "worker_id": self.worker_id},
            max(60, self.settings.unsupported_retry_seconds),
        )

    def process_job(self, conn, job: dict[str, Any]) -> dict[str, Any]:
        if job["tipo_job"] == "detalhes_processo":
            return self.process_detalhes_processo(conn, job)
        if job["tipo_job"] == "consulta_processos":
            return self.process_consulta_processos(conn, job)
        if job["tipo_job"] in {"dados_cpf", "dados_cnpj"}:
            return self.process_dados_pessoais(conn, job)
        if job["tipo_job"] == "pdf_processo":
            return self.process_pdf_processo(conn, job)
        return {"ok": False, "terminal": True, "message": f"Tipo de job desconhecido: {job['tipo_job']}"}

    def process_consulta_processos(self, conn, job: dict[str, Any]) -> dict[str, Any]:
        consulta = self.get_or_create_consulta(conn, job, "consulta_processos")
        if str(consulta.get("modalidade_pedido") or "") == "detalhes_processo":
            return self.process_detalhes_processo(conn, job)

        consulta_id = int(consulta["id_consulta"])
        entrada = only_digits(str(consulta.get("entrada_normalizada") or consulta.get("entrada_original") or ""))
        if len(entrada) not in (11, 14, 20):
            message = "Documento invalido para consulta de processos."
            self.mark_consulta_error(conn, consulta_id, message, {"id_job": job["id_job"], "entrada": entrada})
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        dados = decode_json(consulta.get("dados_json"))
        request_id = self.extract_request_id(dados)

        if not request_id:
            response = self.criar_consulta_partes(format_cnj(entrada)) if len(entrada) == 20 else self.criar_consulta_documento(entrada)
            if not response["ok"]:
                message = response.get("message") or "Nao foi possivel criar a consulta externa."
                self.mark_consulta_error(conn, consulta_id, message, response)
                self.mark_pedido_error_by_consulta(conn, consulta_id, message)
                return {"ok": False, "terminal": True, "message": message}

            request_id = self.extract_request_id(response.get("raw"))
            if not request_id:
                message = "Fornecedor nao retornou request_id da consulta."
                self.mark_consulta_error(conn, consulta_id, message, response)
                self.mark_pedido_error_by_consulta(conn, consulta_id, message)
                return {"ok": False, "terminal": True, "message": message}

            self.mark_consulta_pending(
                conn,
                consulta_id,
                response.get("message") or "Solicitacao em andamento",
                {"request_id": request_id, "createResponse": response.get("raw")},
            )
            return {
                "ok": True,
                "pending": True,
                "retry_after": retry_seconds(int(job.get("tentativas") or 1)),
                "message": "Consulta externa criada e aguardando resultado.",
            }

        response = self.obter_resultado_partes(request_id) if len(entrada) == 20 else self.obter_resultado_documento_completo(request_id)
        if not response["ok"]:
            message = response.get("message") or "Nao foi possivel obter o resultado da consulta externa."
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        raw = response.get("raw") if isinstance(response.get("raw"), dict) else {}
        status = self.extract_status(raw) or "success"
        message = str(response.get("message") or "Consulta realizada com sucesso")
        if status in PENDING_EXTERNAL_STATUSES:
            self.mark_consulta_pending(conn, consulta_id, message, {"request_id": request_id, "resultResponse": raw})
            return {
                "ok": True,
                "pending": True,
                "retry_after": retry_seconds(int(job.get("tentativas") or 1)),
                "message": message,
            }

        if status in TERMINAL_EXTERNAL_STATUSES:
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        creditos_externos = self.external_cost(conn, consulta)
        validade = (datetime.now() + timedelta(days=7)).strftime("%Y-%m-%d %H:%M:%S")
        self.mark_consulta_success(conn, consulta_id, {"message": message, "raw": raw}, creditos_externos, validade)
        if creditos_externos > Decimal("0"):
            self.register_external_debit(
                conn,
                str(consulta.get("fornecedor") or "consultaprocesso"),
                consulta_id,
                creditos_externos,
                "Consumo externo em consulta_processos pelo worker Python",
            )
        self.mark_pending_pedido_success(
            conn,
            consulta_id,
            str(consulta.get("fornecedor") or "consultaprocesso"),
            creditos_externos,
            "Consulta processada pela fila Python.",
        )
        return {"ok": True, "message": "Consulta de processos processada pela fila Python."}

    def process_dados_pessoais(self, conn, job: dict[str, Any]) -> dict[str, Any]:
        modalidade = "dados_cnpj" if job["tipo_job"] == "dados_cnpj" else "dados_cpf"
        consulta = self.get_or_create_consulta(conn, job, modalidade)
        consulta_id = int(consulta["id_consulta"])
        if str(consulta.get("status_resultado") or "") == "sucesso":
            return {"ok": True, "message": "Dados pessoais ja estavam disponiveis."}
        if str(consulta.get("status_resultado") or "") not in {"", "pendente"}:
            return {"ok": False, "terminal": True, "message": str(consulta.get("mensagem_resultado") or "Consulta nao esta pendente.")}

        entrada = only_digits(str(consulta.get("entrada_normalizada") or consulta.get("entrada_original") or ""))
        expected_len = 14 if modalidade == "dados_cnpj" else 11
        if len(entrada) != expected_len:
            message = "Documento invalido para dados pessoais."
            self.mark_consulta_error(conn, consulta_id, message, {"id_job": job["id_job"], "entrada": entrada})
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        response = self.consultar_hubdev(entrada, modalidade)
        if not response["ok"]:
            message = response.get("message") or "Consulta externa nao retornou resultado."
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        creditos_externos = self.external_cost(conn, consulta)
        if creditos_externos <= Decimal("0"):
            creditos_externos = Decimal(str(response.get("consumed") or 0))

        dias = 90 if modalidade == "dados_cnpj" else 30
        validade = (datetime.now() + timedelta(days=dias)).strftime("%Y-%m-%d %H:%M:%S")
        raw = response.get("raw") if isinstance(response.get("raw"), dict) else response
        last_update = None
        result = response.get("result") if isinstance(response.get("result"), dict) else {}
        if modalidade == "dados_cnpj":
            last_update = result.get("lastUpdate") or result.get("last_update")

        self.mark_consulta_success(
            conn,
            consulta_id,
            {"message": response.get("message") or "Consulta realizada com sucesso", "raw": raw},
            creditos_externos,
            validade,
            last_update_cnpj=last_update,
        )
        if creditos_externos > Decimal("0"):
            self.register_external_debit(
                conn,
                str(consulta.get("fornecedor") or "hubdev"),
                consulta_id,
                creditos_externos,
                f"Consumo externo em {modalidade} pelo worker Python",
            )
        self.mark_pending_pedido_success(
            conn,
            consulta_id,
            str(consulta.get("fornecedor") or "hubdev"),
            creditos_externos,
            "Consulta processada pela fila Python.",
        )
        return {"ok": True, "message": "Dados pessoais processados pela fila Python."}

    def process_pdf_processo(self, conn, job: dict[str, Any]) -> dict[str, Any]:
        consulta = self.get_or_create_consulta(conn, job, "pdf_processo")
        consulta_id = int(consulta["id_consulta"])
        entrada = only_digits(str(consulta.get("entrada_normalizada") or consulta.get("entrada_original") or ""))
        if len(entrada) != 20:
            message = "CNJ invalido para PDF do processo."
            self.mark_consulta_error(conn, consulta_id, message, {"id_job": job["id_job"], "entrada": entrada})
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        if str(consulta.get("status_resultado") or "") == "sucesso":
            if self.pdf_local_exists(str(consulta.get("link_pdf_cliente") or "")):
                return {"ok": True, "message": "PDF ja estava disponivel."}
            message = "PDF marcado como disponivel, mas o arquivo local nao foi encontrado."
            self.mark_consulta_error(conn, consulta_id, message, {"id_job": job["id_job"]})
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        dados = decode_json(consulta.get("dados_json"))
        payload = decode_json(job.get("payload_json"))
        req_id = int(payload.get("req_id") or dados.get("req_id") or dados.get("reqId") or 0)

        if req_id <= 0:
            response = self.criar_pdf_processo(format_cnj(entrada))
            if not response["ok"]:
                message = response.get("message") or "Nao foi possivel criar requisicao do PDF."
                self.mark_consulta_error(conn, consulta_id, message, response)
                self.mark_pedido_error_by_consulta(conn, consulta_id, message)
                return {"ok": False, "terminal": True, "message": message}

            req_id = self.extract_pdf_req_id(response.get("raw"))
            if req_id <= 0:
                message = "Processo Rapido nao retornou ID da requisicao."
                self.mark_consulta_error(conn, consulta_id, message, response)
                self.mark_pedido_error_by_consulta(conn, consulta_id, message)
                return {"ok": False, "terminal": True, "message": message}

            self.mark_consulta_pending(
                conn,
                consulta_id,
                "Consultando dados e criando PDF completo. A pagina sera atualizada automaticamente quando o PDF estiver disponivel.",
                {"req_id": req_id, "createResponse": response.get("raw")},
            )
            return {
                "ok": True,
                "pending": True,
                "retry_after": retry_seconds(int(job.get("tentativas") or 1)),
                "message": "Requisicao de PDF criada.",
            }

        response = self.obter_pdf_requisicao(req_id)
        if not response["ok"]:
            message = response.get("message") or "Nao foi possivel obter a requisicao do PDF."
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        raw = response.get("raw") if isinstance(response.get("raw"), dict) else {}
        requisicao = raw.get("requisicao") if isinstance(raw.get("requisicao"), dict) else raw
        pdf_url = self.extract_pdf_url(requisicao)
        if not pdf_url:
            self.mark_consulta_pending(
                conn,
                consulta_id,
                str(response.get("message") or "PDF ainda em processamento."),
                {"req_id": req_id, "resultResponse": raw},
            )
            return {
                "ok": True,
                "pending": True,
                "retry_after": retry_seconds(int(job.get("tentativas") or 1)),
                "message": "PDF ainda em processamento.",
            }

        local_path = self.download_pdf_to_storage(entrada, pdf_url)
        creditos_externos = self.external_cost(conn, consulta)
        validade = (datetime.now() + timedelta(days=self.settings.processo_rapido_pdf_retention_days)).strftime("%Y-%m-%d %H:%M:%S")
        link_expira = validade
        self.mark_pdf_success(
            conn,
            consulta_id,
            raw,
            creditos_externos,
            validade,
            pdf_url,
            local_path,
            link_expira,
        )
        if creditos_externos > Decimal("0"):
            self.register_external_debit(
                conn,
                str(consulta.get("fornecedor") or "processorapido"),
                consulta_id,
                creditos_externos,
                "Consumo externo em pdf_processo pelo worker Python",
            )
        self.mark_pending_pedido_success(
            conn,
            consulta_id,
            str(consulta.get("fornecedor") or "processorapido"),
            creditos_externos,
            "PDF processado pela fila Python.",
        )
        return {"ok": True, "message": "PDF processado pela fila Python."}

    def process_detalhes_processo(self, conn, job: dict[str, Any]) -> dict[str, Any]:
        consulta = self.get_or_create_consulta(conn, job, "detalhes_processo")
        consulta_id = int(consulta["id_consulta"])
        entrada = only_digits(str(consulta.get("entrada_normalizada") or ""))

        if len(entrada) != 20:
            self.mark_consulta_error(conn, consulta_id, "Consulta CNJ invalida para processamento.", {"id_job": job["id_job"]})
            self.mark_pedido_error_by_consulta(conn, consulta_id, "Consulta CNJ invalida para processamento.")
            return {"ok": False, "terminal": True, "message": "Consulta CNJ invalida para processamento."}

        response = self.consultar_processo_por_cnj(format_cnj(entrada))
        if not response["ok"]:
            message = response.get("message") or "Nao foi possivel consultar o processo por CNJ."
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        raw = response.get("raw") if isinstance(response.get("raw"), dict) else {}
        status = self.extract_status(raw) or "done"
        message = str(response.get("message") or "Consulta realizada com sucesso")

        if status in PENDING_EXTERNAL_STATUSES:
            self.mark_consulta_pending(conn, consulta_id, message, {"resultResponse": raw})
            return {
                "ok": True,
                "pending": True,
                "retry_after": retry_seconds(int(job.get("tentativas") or 1)),
                "message": message or "Consulta por CNJ em processamento.",
            }

        if status in TERMINAL_EXTERNAL_STATUSES:
            message = message or f"Consulta externa retornou status {status}."
            self.mark_consulta_error(conn, consulta_id, message, response)
            self.mark_pedido_error_by_consulta(conn, consulta_id, message)
            return {"ok": False, "terminal": True, "message": message}

        creditos_externos = self.external_cost(conn, consulta)
        validade = (datetime.now() + timedelta(days=30)).strftime("%Y-%m-%d %H:%M:%S")
        self.mark_consulta_success(
            conn,
            consulta_id,
            {"message": message, "raw": raw},
            creditos_externos,
            validade,
        )

        if creditos_externos > Decimal("0"):
            self.register_external_debit(
                conn,
                str(consulta.get("fornecedor") or "consultaprocesso"),
                consulta_id,
                creditos_externos,
                "Consumo externo em detalhes_processo pelo worker Python",
            )

        self.mark_pending_pedido_success(
            conn,
            consulta_id,
            str(consulta.get("fornecedor") or "consultaprocesso"),
            creditos_externos,
            "Consulta processada pela fila Python.",
        )

        return {"ok": True, "message": "Consulta por CNJ processada pela fila Python."}

    def consultar_processo_por_cnj(self, cnj: str) -> dict[str, Any]:
        if not self.settings.consulta_api_key:
            return {"ok": False, "message": "Token da Consulta de Processos nao configurado.", "raw": None}

        url = self.settings.consulta_base_url + "/" + self.settings.consulta_cnj_endpoint.lstrip("/")
        return self.consulta_request("POST", url, json_payload={"cnj": cnj})

    def criar_consulta_documento(self, documento: str) -> dict[str, Any]:
        if not self.settings.consulta_api_key:
            return {"ok": False, "message": "Token da Consulta de Processos nao configurado.", "raw": None}
        url = self.settings.consulta_base_url + "/" + self.settings.consulta_document_create_endpoint.lstrip("/")
        return self.consulta_request("POST", url, json_payload={"document": documento})

    def obter_resultado_documento(self, request_id: str, page: int = 1, include_filters: bool = True) -> dict[str, Any]:
        endpoint = self.settings.consulta_document_result_endpoint.replace("{requestId}", request_id)
        url = self.settings.consulta_base_url + "/" + endpoint.lstrip("/")
        return self.consulta_request(
            "GET",
            url,
            params={"page": max(1, page), "perPage": 100, "includeFilters": "true" if include_filters else "false"},
        )

    def obter_resultado_documento_completo(self, request_id: str) -> dict[str, Any]:
        primeira = self.obter_resultado_documento(request_id, 1, True)
        if not primeira["ok"]:
            return primeira
        raw = primeira.get("raw") if isinstance(primeira.get("raw"), dict) else {}
        status = self.extract_status(raw) or "success"
        if status in PENDING_EXTERNAL_STATUSES or status in TERMINAL_EXTERNAL_STATUSES:
            return primeira

        pagination = raw.get("pagination") if isinstance(raw.get("pagination"), dict) else {}
        total_pages = max(1, min(1000, int(pagination.get("totalPages") or 1)))
        if total_pages <= 1:
            return primeira

        processos = self.extract_lawsuits(raw)
        warnings: list[dict[str, Any]] = []
        for page in range(2, total_pages + 1):
            pagina = self.obter_resultado_documento(request_id, page, False)
            if not pagina["ok"]:
                warnings.append({"page": page, "message": pagina.get("message"), "http_code": pagina.get("http_code")})
                break
            raw_page = pagina.get("raw") if isinstance(pagina.get("raw"), dict) else {}
            page_status = self.extract_status(raw_page) or "success"
            if page_status in PENDING_EXTERNAL_STATUSES or page_status in TERMINAL_EXTERNAL_STATUSES:
                warnings.append({"page": page, "status": page_status, "message": pagina.get("message")})
                break
            processos.extend(self.extract_lawsuits(raw_page))

        raw = self.replace_lawsuits(raw, processos)
        raw["pagination"] = {
            **pagination,
            "page": 1,
            "perPage": 100,
            "totalFetched": len(processos),
            "fetchedPages": min(total_pages, 1 + max(0, len(processos) - 1) // 100),
        }
        if warnings:
            raw["paginationWarnings"] = warnings
        primeira["raw"] = raw
        return primeira

    def criar_consulta_partes(self, cnj: str) -> dict[str, Any]:
        if not self.settings.consulta_api_key:
            return {"ok": False, "message": "Token da Consulta de Processos nao configurado.", "raw": None}
        url = self.settings.consulta_base_url + "/" + self.settings.consulta_parties_create_endpoint.lstrip("/")
        return self.consulta_request("POST", url, json_payload={"cnj": cnj})

    def obter_resultado_partes(self, request_id: str) -> dict[str, Any]:
        endpoint = self.settings.consulta_parties_result_endpoint.replace("{requestId}", request_id)
        url = self.settings.consulta_base_url + "/" + endpoint.lstrip("/")
        return self.consulta_request("GET", url)

    def consulta_request(
        self,
        method: str,
        url: str,
        json_payload: dict[str, Any] | None = None,
        params: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        try:
            resp = requests.request(
                method,
                url,
                headers={
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "Authorization": f"Bearer {self.settings.consulta_api_key}",
                },
                json=json_payload,
                params=params,
                timeout=self.settings.http_timeout,
            )
            try:
                raw: Any = resp.json()
            except ValueError:
                raw = {"body": resp.text}

            status = self.extract_status(raw)
            is_async_pending = resp.status_code == 404 and status in PENDING_EXTERNAL_STATUSES
            ok = 200 <= resp.status_code < 300 or is_async_pending
            message = self.extract_message(raw) or ("Consulta realizada com sucesso" if ok else f"HTTP {resp.status_code}")
            return {"ok": ok, "message": message, "http_code": resp.status_code, "raw": raw}
        except requests.RequestException as exc:
            return {"ok": False, "message": str(exc), "http_code": 0, "raw": None}

    def consultar_hubdev(self, documento: str, modalidade: str) -> dict[str, Any]:
        if not self.settings.hubdev_token:
            return {"ok": False, "message": "Token HubDev nao configurado.", "raw": None}

        contract = self.settings.hubdev_contract.strip()
        if contract.startswith("&"):
            contract = contract[1:]
        if contract.startswith("contract="):
            contract = contract[len("contract=") :]

        endpoint = "/cnpj/" if modalidade == "dados_cnpj" else "/cadastropf/"
        param = "cnpj" if modalidade == "dados_cnpj" else "cpf"
        timeout = max(self.settings.http_timeout, 310 if modalidade == "dados_cnpj" else 35)
        try:
            resp = requests.get(
                self.settings.hubdev_base_url + endpoint,
                params={param: documento, "token": self.settings.hubdev_token, "contract": contract},
                timeout=timeout,
            )
            try:
                raw: Any = resp.json()
            except ValueError:
                raw = {"body": resp.text}
            ok = 200 <= resp.status_code < 300 and str((raw or {}).get("return") or "").upper() == "OK"
            message = self.extract_message(raw) or ("Consulta realizada com sucesso" if ok else f"HTTP {resp.status_code}")
            result = raw.get("result") if isinstance(raw, dict) else None
            consumed = raw.get("consumed") if isinstance(raw, dict) else 0
            return {"ok": ok, "message": message, "http_code": resp.status_code, "raw": raw, "result": result, "consumed": consumed}
        except requests.RequestException as exc:
            return {"ok": False, "message": str(exc), "http_code": 0, "raw": None}

    def criar_pdf_processo(self, cnj: str) -> dict[str, Any]:
        if not self.settings.processo_rapido_api_key:
            return {"ok": False, "message": "Chave da API Processo Rapido nao configurada.", "raw": None}
        url = self.settings.processo_rapido_base_url + "/req/criar"
        return self.processo_rapido_request("POST", url, params={"api_key": self.settings.processo_rapido_api_key}, data=[("processos[]", cnj)])

    def obter_pdf_requisicao(self, req_id: int) -> dict[str, Any]:
        if not self.settings.processo_rapido_api_key:
            return {"ok": False, "message": "Chave da API Processo Rapido nao configurada.", "raw": None}
        url = self.settings.processo_rapido_base_url + "/req/obter"
        return self.processo_rapido_request("GET", url, params={"api_key": self.settings.processo_rapido_api_key, "req_id": req_id})

    def processo_rapido_request(
        self,
        method: str,
        url: str,
        params: dict[str, Any],
        data: list[tuple[str, Any]] | None = None,
    ) -> dict[str, Any]:
        try:
            resp = requests.request(
                method,
                url,
                params=params,
                data=data,
                headers={"Accept": "application/json"},
                timeout=max(self.settings.http_timeout, 90),
            )
            try:
                raw: Any = resp.json()
            except ValueError:
                raw = {"body": resp.text}
            message = self.extract_message(raw) or ("Requisicao realizada com sucesso" if 200 <= resp.status_code < 300 else f"HTTP {resp.status_code}")
            return {"ok": 200 <= resp.status_code < 300, "message": message, "http_code": resp.status_code, "raw": raw}
        except requests.RequestException as exc:
            return {"ok": False, "message": str(exc), "http_code": 0, "raw": None}

    def download_pdf_to_storage(self, numero_normalizado: str, url: str) -> str:
        storage = self.resolve_storage_dir()
        storage.mkdir(parents=True, exist_ok=True)
        path = storage / f"{numero_normalizado}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.pdf"
        tmp = Path(str(path) + ".download")
        content_type = ""
        try:
            with requests.get(self.normalize_pdf_url(url), stream=True, timeout=300) as resp:
                content_type = resp.headers.get("Content-Type", "")
                resp.raise_for_status()
                with tmp.open("wb") as handle:
                    for chunk in resp.iter_content(chunk_size=1024 * 256):
                        if chunk:
                            handle.write(chunk)
            if not tmp.is_file() or tmp.stat().st_size <= 0:
                raise RuntimeError("O Processo Rapido nao retornou um PDF valido.")
            with tmp.open("rb") as handle:
                first_bytes = handle.read(4)
            if "html" in content_type.lower() and first_bytes != b"%PDF":
                raise RuntimeError("O link retornou uma pagina HTML em vez do PDF.")
            tmp.replace(path)
            return str(path)
        finally:
            if tmp.exists():
                tmp.unlink(missing_ok=True)

    def normalize_pdf_url(self, url: str) -> str:
        if "dropbox.com" not in url.lower():
            return url
        if "dl=0" in url:
            return url.replace("dl=0", "dl=1")
        return url + ("?dl=1" if "?" not in url else "&dl=1")

    def get_or_create_consulta(self, conn, job: dict[str, Any], modalidade_padrao: str) -> dict[str, Any]:
        consultas = self.settings.table("consultas_externas")
        pedidos = self.settings.table("pedidos_usuarios")
        jobs = self.settings.table("jobs")
        payload = decode_json(job.get("payload_json"))
        consulta_id = int(job.get("id_consulta") or 0)

        with conn.cursor() as cur:
            if consulta_id > 0:
                cur.execute(f"SELECT * FROM {consultas} WHERE id_consulta = %s LIMIT 1", (consulta_id,))
                row = cur.fetchone()
                if row:
                    return row

            pedido_id = int(job.get("id_consulta_usuario") or 0)
            pedido = None
            if pedido_id > 0:
                cur.execute(f"SELECT * FROM {pedidos} WHERE id_consulta_usuario = %s LIMIT 1", (pedido_id,))
                pedido = cur.fetchone()
                pedido_consulta_id = int((pedido or {}).get("id_consulta") or 0)
                if pedido_consulta_id > 0:
                    cur.execute(f"SELECT * FROM {consultas} WHERE id_consulta = %s LIMIT 1", (pedido_consulta_id,))
                    row = cur.fetchone()
                    if row:
                        return row

            modalidade = str(payload.get("modalidade_pedido") or (pedido or {}).get("modalidade_pedido") or modalidade_padrao)
            entrada_original = str(
                payload.get("entrada_original")
                or (pedido or {}).get("entrada_original")
                or (pedido or {}).get("entrada_normalizada")
                or job.get("entrada_original")
                or ""
            )
            entrada_normalizada = str(
                payload.get("entrada_normalizada")
                or (pedido or {}).get("entrada_normalizada")
                or job.get("entrada_normalizada")
                or only_digits(entrada_original)
            )
            fornecedor = str(payload.get("fornecedor") or (pedido or {}).get("fornecedor") or job.get("fornecedor") or "consultaprocesso")

            if not modalidade or not entrada_normalizada:
                raise RuntimeError("Job sem modalidade ou entrada normalizada.")

            cur.execute(
                f"""
                INSERT INTO {consultas}
                    (modalidade_pedido, entrada_original, entrada_normalizada, fornecedor, status_resultado)
                VALUES (%s, %s, %s, %s, 'pendente')
                """,
                (modalidade, entrada_original, entrada_normalizada, fornecedor),
            )
            consulta_id = int(cur.lastrowid)

            self.mark_consulta_pending(
                conn,
                consulta_id,
                "Consulta criada pela fila Python.",
                {"queued": True, "id_job": job["id_job"], "id_consulta_usuario": pedido_id or None},
            )

            if pedido_id > 0:
                cur.execute(
                    f"""
                    UPDATE {pedidos}
                       SET id_consulta = %s,
                           origem = 'web',
                           fornecedor = %s,
                           status_resultado = 'pendente',
                           mensagem_resultado = 'Consulta criada pela fila Python.'
                     WHERE id_consulta_usuario = %s
                    """,
                    (consulta_id, fornecedor, pedido_id),
                )

            cur.execute(
                f"UPDATE {jobs} SET id_consulta = %s, updated_at = NOW() WHERE id_job = %s",
                (consulta_id, job["id_job"]),
            )

            cur.execute(f"SELECT * FROM {consultas} WHERE id_consulta = %s LIMIT 1", (consulta_id,))
            row = cur.fetchone()
            if not row:
                raise RuntimeError("Consulta criada, mas nao encontrada para processamento.")
            return row

    def extract_message(self, raw: Any) -> str | None:
        if isinstance(raw, dict):
            for key in ("message", "mensagem", "error", "erro"):
                value = raw.get(key)
                if value:
                    return str(value)
        return None

    def extract_status(self, raw: Any) -> str:
        if not isinstance(raw, dict):
            return ""

        status = raw.get("status")
        if status:
            return str(status).lower()

        for key in ("data", "result", "resultResponse", "createResponse"):
            nested = raw.get(key)
            if isinstance(nested, dict) and nested.get("status"):
                return str(nested["status"]).lower()

        return ""

    def extract_request_id(self, raw: Any) -> str:
        if not isinstance(raw, dict):
            return ""
        for key in ("request_id", "requestId", "id", "uuid"):
            value = raw.get(key)
            if value:
                return str(value)
        for key in ("data", "result", "resultResponse", "createResponse"):
            nested = raw.get(key)
            if isinstance(nested, dict):
                found = self.extract_request_id(nested)
                if found:
                    return found
        return ""

    def extract_lawsuits(self, raw: dict[str, Any]) -> list[dict[str, Any]]:
        candidates = [
            raw.get("lawsuits"),
            raw.get("processes"),
            raw.get("processos"),
            raw.get("items"),
            raw.get("results"),
            (raw.get("data") or {}).get("lawsuits") if isinstance(raw.get("data"), dict) else None,
            (raw.get("data") or {}).get("processes") if isinstance(raw.get("data"), dict) else None,
            (raw.get("data") or {}).get("processos") if isinstance(raw.get("data"), dict) else None,
            (raw.get("result") or {}).get("lawsuits") if isinstance(raw.get("result"), dict) else None,
            (raw.get("result") or {}).get("processes") if isinstance(raw.get("result"), dict) else None,
            (raw.get("result") or {}).get("processos") if isinstance(raw.get("result"), dict) else None,
        ]
        for candidate in candidates:
            if isinstance(candidate, list):
                return [item for item in candidate if isinstance(item, dict)]
        return []

    def replace_lawsuits(self, raw: dict[str, Any], lawsuits: list[dict[str, Any]]) -> dict[str, Any]:
        for key in ("lawsuits", "processes", "processos", "items", "results"):
            if key in raw:
                raw[key] = lawsuits
                return raw
        for parent in ("data", "result"):
            nested = raw.get(parent)
            if isinstance(nested, dict):
                for key in ("lawsuits", "processes", "processos", "items"):
                    if key in nested:
                        nested[key] = lawsuits
                        return raw
        raw["lawsuits"] = lawsuits
        return raw

    def extract_pdf_req_id(self, raw: Any) -> int:
        if not isinstance(raw, dict):
            return 0
        requisicoes = raw.get("requisicoes")
        if isinstance(requisicoes, list) and requisicoes:
            first = requisicoes[0]
            if isinstance(first, dict) and first.get("id"):
                return int(first["id"])
        for key in ("req_id", "reqId", "id"):
            value = raw.get(key)
            if value:
                return int(value)
        return 0

    def extract_pdf_url(self, raw: Any) -> str:
        if not isinstance(raw, dict):
            return ""
        for key in ("pdf", "pdf_url", "pdfUrl", "url", "link", "arquivo", "download"):
            value = raw.get(key)
            if isinstance(value, str) and value.strip():
                return value.strip()
        for key in ("resultado", "result", "data", "requisicao"):
            nested = raw.get(key)
            if isinstance(nested, dict):
                found = self.extract_pdf_url(nested)
                if found:
                    return found
        return ""

    def pdf_local_exists(self, db_path: str) -> bool:
        if not db_path:
            return False
        candidate = Path(db_path)
        if not candidate.is_absolute():
            candidate = Path.cwd() / db_path
        if candidate.is_file():
            return True
        storage = self.resolve_storage_dir()
        fallback = storage / Path(db_path).name
        return fallback.is_file()

    def pdf_db_path(self, local_path: str) -> str:
        storage = self.resolve_storage_dir()
        try:
            return "storage/processos_pdf/" + Path(local_path).resolve().relative_to(storage.resolve()).as_posix()
        except ValueError:
            return "storage/processos_pdf/" + Path(local_path).name

    def mark_consulta_pending(self, conn, consulta_id: int, message: str, payload: dict[str, Any]) -> None:
        consultas = self.settings.table("consultas_externas")
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {consultas}
                   SET data_resposta = NOW(),
                       status_resultado = 'pendente',
                       mensagem_resultado = %s,
                       dados_json = %s
                 WHERE id_consulta = %s
                """,
                (message, json_dumps(payload), consulta_id),
            )

    def mark_consulta_success(
        self,
        conn,
        consulta_id: int,
        data: dict[str, Any],
        external_cost: Decimal,
        valid_until: str,
        last_update_cnpj: Any = None,
    ) -> None:
        consultas = self.settings.table("consultas_externas")
        message = str(data.get("message") or "Consulta realizada com sucesso")
        raw = data.get("raw") if isinstance(data.get("raw"), dict) else data
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {consultas}
                   SET data_resposta = NOW(),
                       status_resultado = 'sucesso',
                       mensagem_resultado = %s,
                       dados_json = %s,
                       data_validade = %s,
                       creditos_externo_consumido = %s,
                       last_update_cnpj = %s
                 WHERE id_consulta = %s
                """,
                (message, json_dumps(raw), valid_until, external_cost, last_update_cnpj, consulta_id),
            )

    def mark_pdf_success(
        self,
        conn,
        consulta_id: int,
        raw: dict[str, Any],
        external_cost: Decimal,
        valid_until: str,
        original_url: str,
        local_path: str,
        link_expires_at: str,
    ) -> None:
        consultas = self.settings.table("consultas_externas")
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {consultas}
                   SET data_resposta = NOW(),
                       status_resultado = 'sucesso',
                       mensagem_resultado = %s,
                       dados_json = %s,
                       data_validade = %s,
                       creditos_externo_consumido = %s,
                       link_original_pdf = %s,
                       link_pdf_cliente = %s,
                       link_expira_em = %s
                 WHERE id_consulta = %s
                """,
                (
                    "PDF processado com sucesso.",
                    json_dumps(raw),
                    valid_until,
                    external_cost,
                    original_url,
                    self.pdf_db_path(local_path),
                    link_expires_at,
                    consulta_id,
                ),
            )

    def mark_consulta_error(self, conn, consulta_id: int, message: str, payload: dict[str, Any]) -> None:
        consultas = self.settings.table("consultas_externas")
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {consultas}
                   SET data_resposta = NOW(),
                       status_resultado = 'erro',
                       mensagem_resultado = %s,
                       dados_json = %s
                 WHERE id_consulta = %s
                """,
                (message, json_dumps(payload), consulta_id),
            )

    def mark_pending_pedido_success(
        self,
        conn,
        consulta_id: int,
        fornecedor: str,
        external_cost: Decimal,
        message: str,
    ) -> None:
        pedidos = self.settings.table("pedidos_usuarios")
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE {pedidos}
                   SET origem = 'web',
                       fornecedor = %s,
                       status_resultado = 'sucesso',
                       mensagem_resultado = %s,
                       credito_externo_consumido = %s
                 WHERE id_consulta = %s
                   AND status_resultado = 'pendente'
                 ORDER BY data_pedido DESC, id_consulta_usuario DESC
                 LIMIT 1
                """,
                (fornecedor, message, external_cost, consulta_id),
            )

    def mark_pedido_error_by_consulta(self, conn, consulta_id: int, message: str) -> None:
        pedidos = self.settings.table("pedidos_usuarios")
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT *
                  FROM {pedidos}
                 WHERE id_consulta = %s
                   AND status_resultado = 'pendente'
                 ORDER BY data_pedido DESC, id_consulta_usuario DESC
                 LIMIT 1
                """,
                (consulta_id,),
            )
            pedido = cur.fetchone()
            if not pedido:
                return

            pedido_id = int(pedido["id_consulta_usuario"])
            creditos = Decimal(str(pedido.get("creditos_usuario_consumidos") or 0))
            estorno_id = pedido.get("credito_estorno_transacao_id")
            if creditos > Decimal("0") and not estorno_id:
                estorno = self.estornar_creditos_usuario(
                    int(pedido["id_usuario"]),
                    creditos,
                    self.credit_description(
                        str(pedido.get("modalidade_pedido") or "consulta_processos"),
                        str(pedido.get("entrada_original") or pedido.get("entrada_normalizada") or ""),
                    ),
                )
                estorno_id = estorno.get("transacao_id")
                if estorno_id:
                    cur.execute(
                        f"UPDATE {pedidos} SET credito_estorno_transacao_id = %s WHERE id_consulta_usuario = %s",
                        (str(estorno_id), pedido_id),
                    )

            cur.execute(
                f"""
                UPDATE {pedidos}
                   SET status_resultado = %s,
                       mensagem_resultado = %s
                 WHERE id_consulta_usuario = %s
                """,
                ("estornado" if creditos > Decimal("0") else "erro", message, pedido_id),
            )

    def estornar_creditos_usuario(self, id_usuario: int, creditos: Decimal, referencia: str) -> dict[str, Any]:
        if not self.settings.credit_api_token:
            raise RuntimeError("Token da API de creditos nao configurado para estorno.")
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "Authorization": self.credit_authorization_value(),
        }
        if self.settings.credit_api_host_header:
            headers["Host"] = self.settings.credit_api_host_header

        payload = {
            "tipo": "C",
            "valor": f"{creditos:.2f}",
            "descricao": "Estorno: " + referencia,
        }
        url = self.settings.credit_api_base_url + "/idusuario/" + str(id_usuario)
        try:
            resp = requests.post(
                url,
                headers=headers,
                json=payload,
                timeout=(self.settings.credit_api_connect_timeout, self.settings.credit_api_timeout),
            )
            try:
                raw: Any = resp.json()
            except ValueError:
                raw = {"body": resp.text}
            if resp.status_code < 200 or resp.status_code >= 300:
                message = self.extract_message(raw) or f"API de creditos retornou HTTP {resp.status_code}."
                raise RuntimeError(message)
            return {"raw": raw, "transacao_id": self.extract_transaction_id(raw)}
        except requests.RequestException as exc:
            raise RuntimeError("Erro ao conectar na API de creditos: " + str(exc)) from exc

    def credit_authorization_value(self) -> str:
        prefix = self.settings.credit_api_auth_prefix.strip()
        return self.settings.credit_api_token if not prefix else prefix + " " + self.settings.credit_api_token

    def extract_transaction_id(self, raw: Any) -> str | None:
        if not isinstance(raw, dict):
            return None
        for key in ("transacao_id", "transaction_id", "id_transacao", "id", "codigo"):
            value = raw.get(key)
            if value:
                return str(value)
        for value in raw.values():
            if isinstance(value, dict):
                found = self.extract_transaction_id(value)
                if found:
                    return found
            if isinstance(value, list):
                for item in value:
                    found = self.extract_transaction_id(item)
                    if found:
                        return found
        return None

    def credit_description(self, modalidade: str, entrada_original: str) -> str:
        label = "PDF do processo" if modalidade == "pdf_processo" else "Consulta de processo"
        return f"{label} em {datetime.now().strftime('%d/%m/%Y %H:%M')} - CNJ {entrada_original.strip()}"

    def external_cost(self, conn, consulta: dict[str, Any]) -> Decimal:
        custos = self.settings.table("custos")
        tipo_pedido = self.settings.table("tipo_pedido")
        modalidade = str(consulta.get("modalidade_pedido") or "detalhes_processo")
        with conn.cursor() as cur:
            cur.execute(f"SELECT custo_externo FROM {custos} WHERE chave = %s AND is_ativo = 1 LIMIT 1", (modalidade,))
            row = cur.fetchone()
            if row:
                return Decimal(str(row.get("custo_externo") or 0))

            cur.execute(f"SELECT creditos_externo FROM {tipo_pedido} WHERE modalidade_pedido = %s AND is_ativo = 1 LIMIT 1", (modalidade,))
            row = cur.fetchone()
            return Decimal(str((row or {}).get("creditos_externo") or 0))

    def register_external_debit(self, conn, fornecedor: str, consulta_id: int, amount: Decimal, observation: str) -> None:
        controle = self.settings.table("controle_credito_externo")
        lock_name = f"bidmap_debito_externo_{''.join(ch if ch.isalnum() else '_' for ch in fornecedor)}_{consulta_id}"
        with conn.cursor() as cur:
            cur.execute("SELECT GET_LOCK(%s, 10) AS got_lock", (lock_name,))
            got_lock = int((cur.fetchone() or {}).get("got_lock") or 0) == 1
            try:
                cur.execute(
                    f"""
                    SELECT id_movimento_externo
                      FROM {controle}
                     WHERE fornecedor = %s
                       AND operacao = 'debito'
                       AND id_consulta = %s
                     ORDER BY id_movimento_externo ASC
                     LIMIT 1
                    """,
                    (fornecedor, consulta_id),
                )
                if cur.fetchone():
                    return

                cur.execute(
                    f"""
                    SELECT saldo_final
                      FROM {controle}
                     WHERE fornecedor = %s
                     ORDER BY data_operacao DESC, id_movimento_externo DESC
                     LIMIT 1
                    """,
                    (fornecedor,),
                )
                saldo_anterior = Decimal(str((cur.fetchone() or {}).get("saldo_final") or 0))
                saldo_final = saldo_anterior - amount
                cur.execute(
                    f"""
                    INSERT INTO {controle}
                        (fornecedor, operacao, id_consulta, creditos_movimentados, saldo_anterior, saldo_final, observacao)
                    VALUES (%s, 'debito', %s, %s, %s, %s, %s)
                    """,
                    (fornecedor, consulta_id, amount, saldo_anterior, saldo_final, observation),
                )
            finally:
                if got_lock:
                    cur.execute("SELECT RELEASE_LOCK(%s)", (lock_name,))

    def finish_job(self, conn, job: dict[str, Any], result: dict[str, Any]) -> None:
        id_job = int(job["id_job"])
        if result.get("pending"):
            self.mark_job_error(
                conn,
                id_job,
                str(result.get("message") or "Processamento pendente."),
                {"resultado": result},
                int(result.get("retry_after") or retry_seconds(int(job.get("tentativas") or 1))),
            )
            return

        if result.get("ok") or result.get("terminal"):
            jobs = self.settings.table("jobs")
            with conn.cursor() as cur:
                cur.execute(
                    f"""
                    UPDATE {jobs}
                       SET status_job = 'completed',
                           resultado_json = %s,
                           finished_at = NOW(),
                           worker_id = NULL,
                           last_error = NULL,
                           updated_at = NOW()
                     WHERE id_job = %s
                    """,
                    (json_dumps({"resultado": result}), id_job),
                )
            return

        self.mark_job_error(conn, id_job, str(result.get("message") or "Erro ao processar job Python."), {"resultado": result}, None)

    def mark_job_error(
        self,
        conn,
        id_job: int,
        error: str,
        details: dict[str, Any],
        retry_after: int | None,
    ) -> None:
        jobs = self.settings.table("jobs")
        with conn.cursor() as cur:
            if retry_after is None:
                cur.execute(
                    f"""
                    UPDATE {jobs}
                       SET status_job = 'failed',
                           last_error = %s,
                           erro_json = %s,
                           finished_at = NOW(),
                           worker_id = NULL,
                           updated_at = NOW()
                     WHERE id_job = %s
                    """,
                    (error[:500], json_dumps(details), id_job),
                )
            else:
                cur.execute(
                    f"""
                    UPDATE {jobs}
                       SET status_job = 'pending',
                           last_error = %s,
                           erro_json = %s,
                           next_retry_at = DATE_ADD(NOW(), INTERVAL %s SECOND),
                           available_at = DATE_ADD(NOW(), INTERVAL %s SECOND),
                           worker_id = NULL,
                           redis_enqueued_at = NULL,
                           updated_at = NOW()
                     WHERE id_job = %s
                    """,
                    (error[:500], json_dumps(details), retry_after, retry_after, id_job),
                )


def main(argv: Iterable[str]) -> int:
    args = list(argv)
    settings = Settings()
    worker = PythonConsultaWorker(settings)

    if len(args) > 1 and args[1] == "health":
        return 0 if worker.ping() else 1

    if len(args) > 1 and args[1] == "once":
        result = worker.process_next()
        log(json_dumps(result))
        return 0

    return worker.run_loop()


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))

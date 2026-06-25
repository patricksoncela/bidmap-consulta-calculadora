-- Cria a tabela de jobs da ferramenta de consultas.
--
-- Objetivo:
-- - Redis sera a fila operacional.
-- - MySQL guardara estado, vinculos e auditoria do job.
--
-- Esta tabela nao substitui imediatamente ferramenta_consulta_fila_processos.
-- A fila antiga continua servindo o worker PHP atual durante a migracao.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_jobs` (
  `id_job` int(11) NOT NULL AUTO_INCREMENT,
  `job_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,

  `tipo_job` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fila_redis` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_job` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',

  `id_usuario` int(11) NOT NULL,
  `id_tipo_pedido` int(11) DEFAULT NULL,
  `id_consulta_usuario` int(11) DEFAULT NULL,
  `id_consulta` int(11) DEFAULT NULL,

  `modalidade_pedido` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entrada_original` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entrada_normalizada` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fornecedor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,

  `prioridade` int(11) NOT NULL DEFAULT 100,
  `tentativas` int(11) NOT NULL DEFAULT 0,
  `max_tentativas` int(11) NOT NULL DEFAULT 30,

  `idempotency_key` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redis_enqueued_at` datetime DEFAULT NULL,
  `redis_queue_attempts` int(11) NOT NULL DEFAULT 0,

  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `next_retry_at` datetime DEFAULT NULL,

  `worker_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resultado_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `erro_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,

  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id_job`),
  UNIQUE KEY `fc_jobs_uuid_unica` (`job_uuid`),
  UNIQUE KEY `fc_jobs_idempotency_unica` (`idempotency_key`),
  UNIQUE KEY `fc_jobs_pedido_tipo_unica` (`id_consulta_usuario`, `tipo_job`),

  KEY `fc_jobs_status_queue_idx` (`status_job`, `fila_redis`, `prioridade`, `available_at`, `id_job`),
  KEY `fc_jobs_redis_reconcile_idx` (`status_job`, `redis_enqueued_at`, `created_at`),
  KEY `fc_jobs_retry_idx` (`status_job`, `next_retry_at`, `tentativas`),
  KEY `fc_jobs_usuario_data_idx` (`id_usuario`, `created_at`, `id_job`),
  KEY `fc_jobs_consulta_idx` (`id_consulta`),
  KEY `fc_jobs_pedido_idx` (`id_consulta_usuario`),
  KEY `fc_jobs_tipo_pedido_idx` (`id_tipo_pedido`),
  KEY `fc_jobs_modalidade_entrada_idx` (`modalidade_pedido`, `entrada_normalizada`, `status_job`),

  CONSTRAINT `fc_jobs_tipo_pedido_fk`
    FOREIGN KEY (`id_tipo_pedido`)
    REFERENCES `ferramenta_consulta_tipo_pedido` (`id_tipo_pedido`)
    ON DELETE SET NULL,

  CONSTRAINT `fc_jobs_pedido_fk`
    FOREIGN KEY (`id_consulta_usuario`)
    REFERENCES `ferramenta_consulta_pedidos_usuarios` (`id_consulta_usuario`)
    ON DELETE SET NULL,

  CONSTRAINT `fc_jobs_consulta_fk`
    FOREIGN KEY (`id_consulta`)
    REFERENCES `ferramenta_consulta_consultas_externas` (`id_consulta`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


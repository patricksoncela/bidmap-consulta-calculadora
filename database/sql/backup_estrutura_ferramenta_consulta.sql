-- BACKUP / REFERENCIA da estrutura da ferramenta de consultas.
-- Nao executar em producao sem revisar antes.
-- Pelo banco atual informado, as tabelas principais ja existem e nao precisam ser recriadas.
--
-- Configuracao esperada no .env:
-- CONSULTA_DB_PREFIX=ferramenta_consulta_
--
-- Observacao: este SQL nao cria tabela separada de custos.
-- Os custos editaveis ficam na tabela ferramenta_consulta_tipo_pedido.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_consultas_externas` (
  `id_consulta` int NOT NULL AUTO_INCREMENT,
  `modalidade_pedido` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrada_original` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrada_normalizada` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fornecedor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_consulta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_resposta` datetime DEFAULT NULL,
  `status_resultado` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `mensagem_resultado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dados_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `data_validade` datetime DEFAULT NULL,
  `creditos_externo_consumido` decimal(10,2) NOT NULL DEFAULT '0.00',
  `link_original_pdf` text COLLATE utf8mb4_unicode_ci,
  `link_pdf_cliente` text COLLATE utf8mb4_unicode_ci,
  `link_expira_em` datetime DEFAULT NULL,
  `last_update_cnpj` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_consulta`),
  KEY `fc_consultas_entrada_idx` (`modalidade_pedido`,`entrada_normalizada`,`fornecedor`),
  KEY `fc_consultas_status_idx` (`status_resultado`),
  KEY `fc_consultas_validade_idx` (`data_validade`),
  KEY `fc_consultas_cache_lookup_idx` (`modalidade_pedido`, `entrada_normalizada`, `fornecedor`, `status_resultado`, `data_resposta`, `id_consulta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_tipo_pedido` (
  `id_tipo_pedido` int NOT NULL AUTO_INCREMENT,
  `modalidade_pedido` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fornecedor_padrao` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creditos_usuario` decimal(10,2) NOT NULL DEFAULT '0.00',
  `valor_por_credito_usuario` decimal(10,4) DEFAULT NULL,
  `valor_total_usuario` decimal(10,2) DEFAULT NULL,
  `creditos_externo` decimal(10,2) DEFAULT NULL,
  `valor_por_credito_externo` decimal(10,4) DEFAULT NULL,
  `valor_total_externo` decimal(10,2) DEFAULT NULL,
  `is_ativo` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tipo_pedido`),
  UNIQUE KEY `fc_modalidade_pedido_unica` (`modalidade_pedido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_pedidos_usuarios` (
  `id_consulta_usuario` int NOT NULL AUTO_INCREMENT,
  `id_usuario` int NOT NULL,
  `id_tipo_pedido` int NOT NULL,
  `modalidade_pedido` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrada_original` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrada_normalizada` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_pedido` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_consulta_cache` datetime DEFAULT NULL,
  `id_consulta` int DEFAULT NULL,
  `origem` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fornecedor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_resultado` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `mensagem_resultado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credito_externo_consumido` decimal(10,2) NOT NULL DEFAULT '0.00',
  `creditos_usuario_consumidos` decimal(10,2) NOT NULL DEFAULT '0.00',
  `saldo_anterior_usuario` decimal(10,2) DEFAULT NULL,
  `saldo_posterior_usuario` decimal(10,2) DEFAULT NULL,
  `credito_transacao_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credito_descricao` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credito_estorno_transacao_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_consulta_usuario`),
  KEY `fc_pedidos_id_usuario_idx` (`id_usuario`),
  KEY `fc_pedidos_tipo_entrada_idx` (`modalidade_pedido`,`entrada_normalizada`),
  KEY `fc_pedidos_status_idx` (`status_resultado`),
  KEY `fc_pedidos_data_idx` (`data_pedido`),
  KEY `fc_pedidos_credito_transacao_idx` (`credito_transacao_id`),
  KEY `fc_pedidos_tipo_pedido_idx` (`id_tipo_pedido`),
  KEY `fc_pedidos_consulta_idx` (`id_consulta`),
  KEY `fc_pedidos_usuario_data_idx` (`id_usuario`, `data_pedido`, `id_consulta_usuario`),
  KEY `fc_pedidos_consulta_status_idx` (`id_consulta`, `status_resultado`, `data_pedido`, `id_consulta_usuario`),
  KEY `fc_pedidos_update_lookup_idx` (`id_usuario`, `modalidade_pedido`, `entrada_normalizada`, `status_resultado`, `data_pedido`),
  CONSTRAINT `fc_pedidos_consulta_fk` FOREIGN KEY (`id_consulta`) REFERENCES `ferramenta_consulta_consultas_externas` (`id_consulta`) ON DELETE SET NULL,
  CONSTRAINT `fc_pedidos_tipo_fk` FOREIGN KEY (`id_tipo_pedido`) REFERENCES `ferramenta_consulta_tipo_pedido` (`id_tipo_pedido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_controle_credito_externo` (
  `id_movimento_externo` int NOT NULL AUTO_INCREMENT,
  `fornecedor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operacao` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_consulta` int DEFAULT NULL,
  `data_operacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `creditos_movimentados` decimal(10,2) NOT NULL DEFAULT '0.00',
  `saldo_anterior` decimal(10,2) DEFAULT NULL,
  `saldo_final` decimal(10,2) DEFAULT NULL,
  `observacao` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_movimento_externo`),
  KEY `fc_controle_fornecedor_idx` (`fornecedor`),
  KEY `fc_controle_data_idx` (`data_operacao`),
  KEY `fc_controle_consulta_idx` (`id_consulta`),
  CONSTRAINT `fc_controle_consulta_fk` FOREIGN KEY (`id_consulta`) REFERENCES `ferramenta_consulta_consultas_externas` (`id_consulta`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ferramenta_consulta_fila_processos` (
  `id_fila` int NOT NULL AUTO_INCREMENT,
  `tipo_fila` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_consulta` int NOT NULL,
  `id_consulta_usuario` int DEFAULT NULL,
  `status_fila` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `prioridade` int NOT NULL DEFAULT 100,
  `tentativas` int NOT NULL DEFAULT 0,
  `max_tentativas` int NOT NULL DEFAULT 10,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `mensagem` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disponivel_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_fila`),
  UNIQUE KEY `fc_fila_tipo_consulta_unica` (`tipo_fila`, `id_consulta`),
  KEY `fc_fila_status_idx` (`status_fila`, `disponivel_em`, `prioridade`),
  KEY `fc_fila_consulta_idx` (`id_consulta`),
  CONSTRAINT `fc_fila_consulta_fk` FOREIGN KEY (`id_consulta`) REFERENCES `ferramenta_consulta_consultas_externas` (`id_consulta`) ON DELETE CASCADE,
  CONSTRAINT `fc_fila_pedido_fk` FOREIGN KEY (`id_consulta_usuario`) REFERENCES `ferramenta_consulta_pedidos_usuarios` (`id_consulta_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ferramenta_consulta_tipo_pedido`
  (`id_tipo_pedido`, `modalidade_pedido`, `fornecedor_padrao`, `creditos_usuario`,
   `valor_por_credito_usuario`, `valor_total_usuario`, `creditos_externo`,
   `valor_por_credito_externo`, `valor_total_externo`, `is_ativo`)
VALUES
  (1, 'dados_cpf', 'hubdev', 3.00, 0.3267, 0.98, 0.75, 1.0000, 0.75, 1),
  (2, 'dados_cnpj', 'hubdev', 3.00, 0.3267, 0.98, 0.75, 1.0000, 0.75, 1),
  (3, 'consulta_processos', 'consultaprocesso', 5.00, 0.5820, 2.91, 2.24, 1.0000, 2.24, 1),
  (4, 'pdf_processo', 'processorapido', 12.00, 0.8667, 10.40, 8.00, 1.0000, 8.00, 1),
  (5, 'consulta_ia', NULL, 100.00, 1.0000, 100.00, NULL, NULL, NULL, 1),
  (6, 'detalhes_processo', 'consultaprocesso', 3.00, 0.3633, 1.09, 0.84, 1.0000, 0.84, 1)
ON DUPLICATE KEY UPDATE
  fornecedor_padrao = VALUES(fornecedor_padrao),
  creditos_usuario = VALUES(creditos_usuario),
  valor_por_credito_usuario = VALUES(valor_por_credito_usuario),
  valor_total_usuario = VALUES(valor_total_usuario),
  creditos_externo = VALUES(creditos_externo),
  valor_por_credito_externo = VALUES(valor_por_credito_externo),
  valor_total_externo = VALUES(valor_total_externo),
  is_ativo = VALUES(is_ativo);

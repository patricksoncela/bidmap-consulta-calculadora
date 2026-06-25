-- Migration reexecutavel: permite fila baseada no pedido do usuario antes de
-- existir consultas_externas, sem falhar se parte da alteracao ja foi aplicada.
--
-- Antes de rodar em producao, confira se nao ha duplicidades reais:
-- SELECT tipo_fila, id_consulta_usuario, COUNT(*) total
-- FROM ferramenta_consulta_fila_processos
-- WHERE id_consulta_usuario IS NOT NULL
-- GROUP BY tipo_fila, id_consulta_usuario
-- HAVING COUNT(*) > 1;

SET @fc_fila_fk_delete_rule := (
  SELECT rc.DELETE_RULE
  FROM information_schema.REFERENTIAL_CONSTRAINTS rc
  WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
    AND rc.TABLE_NAME = 'ferramenta_consulta_fila_processos'
    AND rc.CONSTRAINT_NAME = 'fc_fila_consulta_fk'
  LIMIT 1
);

SET @fc_sql := IF(
  @fc_fila_fk_delete_rule IS NOT NULL AND @fc_fila_fk_delete_rule <> 'SET NULL',
  'ALTER TABLE `ferramenta_consulta_fila_processos` DROP FOREIGN KEY `fc_fila_consulta_fk`',
  'SELECT 1'
);
PREPARE fc_stmt FROM @fc_sql;
EXECUTE fc_stmt;
DEALLOCATE PREPARE fc_stmt;

SET @fc_id_consulta_not_null := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ferramenta_consulta_fila_processos'
    AND COLUMN_NAME = 'id_consulta'
    AND IS_NULLABLE = 'NO'
);

SET @fc_sql := IF(
  @fc_id_consulta_not_null > 0,
  'ALTER TABLE `ferramenta_consulta_fila_processos` MODIFY `id_consulta` int(11) DEFAULT NULL',
  'SELECT 1'
);
PREPARE fc_stmt FROM @fc_sql;
EXECUTE fc_stmt;
DEALLOCATE PREPARE fc_stmt;

SET @fc_tipo_pedido_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ferramenta_consulta_fila_processos'
    AND INDEX_NAME = 'fc_fila_tipo_pedido_unica'
);

SET @fc_sql := IF(
  @fc_tipo_pedido_index_exists = 0,
  'ALTER TABLE `ferramenta_consulta_fila_processos` ADD UNIQUE KEY `fc_fila_tipo_pedido_unica` (`tipo_fila`, `id_consulta_usuario`)',
  'SELECT 1'
);
PREPARE fc_stmt FROM @fc_sql;
EXECUTE fc_stmt;
DEALLOCATE PREPARE fc_stmt;

SET @fc_consulta_fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ferramenta_consulta_fila_processos'
    AND CONSTRAINT_NAME = 'fc_fila_consulta_fk'
);

SET @fc_sql := IF(
  @fc_consulta_fk_exists = 0,
  'ALTER TABLE `ferramenta_consulta_fila_processos` ADD CONSTRAINT `fc_fila_consulta_fk` FOREIGN KEY (`id_consulta`) REFERENCES `ferramenta_consulta_consultas_externas` (`id_consulta`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE fc_stmt FROM @fc_sql;
EXECUTE fc_stmt;
DEALLOCATE PREPARE fc_stmt;

-- Indices recomendados para reduzir carga no MySQL da ferramenta de consultas.
-- Execute em producao somente depois de verificar se os indices ainda nao existem.

ALTER TABLE ferramenta_consulta_pedidos_usuarios
  ADD INDEX idx_fc_pedidos_usuario_pedido_modalidade (
    id_usuario,
    id_consulta_usuario,
    modalidade_pedido
  );

ALTER TABLE ferramenta_consulta_pedidos_usuarios
  ADD INDEX idx_fc_pedidos_usuario_status_data (
    id_usuario,
    status_resultado,
    data_pedido,
    id_consulta_usuario
  );

ALTER TABLE ferramenta_consulta_pedidos_usuarios
  ADD INDEX idx_fc_pedidos_consulta_status (
    id_consulta,
    status_resultado,
    data_pedido
  );

ALTER TABLE ferramenta_consulta_fila_processos
  ADD INDEX idx_fc_fila_status_disponivel_prioridade (
    status_fila,
    disponivel_em,
    prioridade,
    id_fila
  );

ALTER TABLE ferramenta_consulta_fila_processos
  ADD INDEX idx_fc_fila_status_tentativas (
    status_fila,
    tentativas,
    max_tentativas,
    updated_at
  );

ALTER TABLE ferramenta_consulta_consultas_externas
  ADD INDEX idx_fc_consultas_modalidade_entrada_status (
    modalidade_pedido,
    entrada_normalizada,
    fornecedor,
    status_resultado,
    data_resposta,
    id_consulta
  );

ALTER TABLE ferramenta_consulta_extrato_creditos_usuario
  ADD INDEX idx_fc_extrato_usuario_data (
    id_usuario,
    data_movimentacao,
    id_extrato
  );

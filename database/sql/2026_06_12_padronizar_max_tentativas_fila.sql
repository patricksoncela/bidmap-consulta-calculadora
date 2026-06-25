UPDATE ferramenta_consulta_fila_processos
SET max_tentativas = 30,
    updated_at = NOW()
WHERE status_fila IN ('pendente', 'erro', 'processando')
  AND max_tentativas < 30;

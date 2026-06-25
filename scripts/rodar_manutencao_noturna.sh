#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${BIDMAP_CONSULTAS_APP_DIR:-/opt/bidmap-consultas/app}"
COMPOSE_FILE="${BIDMAP_CONSULTAS_COMPOSE_FILE:-docker/compose.prod.yml}"
LOG_DIR="${BIDMAP_CONSULTAS_LOG_DIR:-/opt/bidmap-consultas/logs}"
AUDIT_DAYS="${BIDMAP_CONSULTAS_AUDIT_DAYS:-7}"

mkdir -p "$LOG_DIR"

cd "$APP_DIR"

timestamp="$(date '+%Y-%m-%d_%H-%M-%S')"
maintenance_log="$LOG_DIR/manutencao_${timestamp}.log"
audit_log="$LOG_DIR/auditoria_${timestamp}.log"

{
  echo "[$(date --iso-8601=seconds)] Iniciando manutencao noturna."
  docker compose -f "$COMPOSE_FILE" run --rm redis-worker php scripts/manutencao_consultas.php --apply --json
  echo "[$(date --iso-8601=seconds)] Manutencao noturna finalizada."
} >> "$maintenance_log" 2>&1

{
  echo "[$(date --iso-8601=seconds)] Iniciando auditoria pos-manutencao."
  docker compose -f "$COMPOSE_FILE" run --rm redis-worker php scripts/auditar_fluxo_consultas.php "$AUDIT_DAYS"
  echo "[$(date --iso-8601=seconds)] Auditoria pos-manutencao finalizada."
} >> "$audit_log" 2>&1

find "$LOG_DIR" -type f \( -name 'manutencao_*.log' -o -name 'auditoria_*.log' \) -mtime +30 -delete

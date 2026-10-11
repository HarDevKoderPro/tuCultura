#!/usr/bin/env bash
# Respaldo de TuCultura: dump de la BD + estado de runtime (imagenes de
# productos y tarjetas-contenido.json), rotacion local y copia cifrada a
# Google Drive con rclone. Se ejecuta a diario desde el crontab de deploy:
#   45 8 * * * /opt/apps/tucultura/scripts/backup.sh >> $HOME/backups/tucultura/backup.log 2>&1
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/apps/tucultura}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/tucultura}"
RETENCION_LOCAL_DIAS="${RETENCION_LOCAL_DIAS:-14}"
RCLONE_REMOTE="${RCLONE_REMOTE-tucultura-crypt:}"
RETENCION_REMOTA_DIAS="${RETENCION_REMOTA_DIAS:-90}"

log() { echo "$(date '+%F %T') $*"; }

umask 077
mkdir -p "$BACKUP_DIR"

marca="$(date +%F_%H%M)"
archivo_db="$BACKUP_DIR/tucultura_db_$marca.sql.gz"
archivo_files="$BACKUP_DIR/tucultura_files_$marca.tar.gz"
temporal="$archivo_db.tmp"
trap 'rm -f "$temporal"' EXIT

log "Iniciando respaldo"
cd "$APP_DIR"

# La contrasena se lee dentro del contenedor y se pasa por MYSQL_PWD para que
# no aparezca en la lista de procesos.
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --triggers --databases "$MARIADB_DATABASE"' \
  </dev/null | gzip -9 > "$temporal"

gzip -t "$temporal"
if ! zcat "$temporal" | tail -n 1 | grep -q 'Dump completed'; then
  log "ERROR: el dump esta incompleto"
  exit 1
fi
mv "$temporal" "$archivo_db"
log "BD respaldada ($(du -h "$archivo_db" | cut -f1))"

tar czf "$archivo_files" 05-tienda/imagenes/productos 05-tienda/php/tarjetas-contenido.json
log "Archivos respaldados ($(du -h "$archivo_files" | cut -f1))"

find "$BACKUP_DIR" -name 'tucultura_*_*.gz' -mtime +"$RETENCION_LOCAL_DIAS" -delete

if [ -n "$RCLONE_REMOTE" ]; then
  if rclone listremotes | grep -qxF "$RCLONE_REMOTE"; then
    rclone copy "$archivo_db" "$RCLONE_REMOTE"
    rclone copy "$archivo_files" "$RCLONE_REMOTE"
    rclone delete --min-age "${RETENCION_REMOTA_DIAS}d" "$RCLONE_REMOTE"
    log "Copia cifrada subida a $RCLONE_REMOTE"
  else
    log "AVISO: remoto $RCLONE_REMOTE no configurado; solo copia local"
  fi
fi

log "Respaldo finalizado"

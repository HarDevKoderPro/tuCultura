#!/usr/bin/env bash
# Despliegue de TuCultura en el VPS.
#
# Lo ejecuta GitHub Actions por SSH como comando forzado de su llave
# (authorized_keys: command="/opt/apps/tucultura/scripts/deploy.sh",...),
# o a mano: /opt/apps/tucultura/scripts/deploy.sh
#
# Actualiza el codigo a origin/main, reconstruye el contenedor app y verifica
# la API de tienda a traves de Caddy. Si la verificacion falla, vuelve al
# commit anterior. Todo va dentro de main() porque git reset reescribe este
# mismo archivo mientras se ejecuta.
set -euo pipefail

main() {
  local app_dir="${APP_DIR:-/opt/apps/tucultura}"
  local rama="${DEPLOY_BRANCH:-main}"
  local url_salud="${HEALTH_URL:-https://vps.girabienes.com/05-tienda/php/categorias.php?action=listar}"

  log() { echo "[deploy $(date '+%F %T')] $*"; }

  cd "$app_dir"

  exec 9>"$app_dir/.deploy.lock"
  if ! flock -n 9; then
    log "ERROR: ya hay un despliegue en curso"
    exit 1
  fi

  local anterior
  anterior="$(git rev-parse HEAD)"

  log "Actualizando codigo desde origin/$rama"
  git fetch --prune origin "$rama"
  git reset --hard "origin/$rama"
  local actual
  actual="$(git rev-parse HEAD)"
  log "Commit: ${anterior:0:7} -> ${actual:0:7} ($(git log -1 --format=%s))"

  construir_y_verificar() {
    docker compose config --quiet
    docker compose up -d --build --remove-orphans
    local intento
    for intento in $(seq 1 20); do
      if curl -fsS --max-time 10 "$url_salud" 2>/dev/null | grep -q '"success":true'; then
        log "Verificacion OK ($url_salud)"
        return 0
      fi
      sleep 3
    done
    log "ERROR: la verificacion de salud fallo"
    docker compose logs --tail=40 app || true
    return 1
  }

  if construir_y_verificar; then
    docker image prune -f >/dev/null
    log "Despliegue completado"
    return 0
  fi

  if [ "$anterior" != "$actual" ]; then
    log "Revirtiendo a ${anterior:0:7}"
    git reset --hard "$anterior"
    construir_y_verificar || log "ERROR: el rollback tampoco paso la verificacion"
  fi
  exit 1
}

main "$@"

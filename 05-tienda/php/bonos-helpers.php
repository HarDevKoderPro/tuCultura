<?php

/**
 * Bonos por puntos acumulados
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Cada PUNTOS_POR_BONO puntos acumulados (puntos_saldo, que suma +1 al comprador
 * y a sus dos antecesores por cada compra aprobada) el asociado obtiene un bono.
 * Los puntos no se descuentan: el bono N se obtiene al llegar a N x 50 puntos.
 *
 * Los bonos se registran en `bonos_puntos` para que el admin haga el
 * seguimiento (pendiente -> entregado). "Eliminar" un bono lo deja en estado
 * 'anulado' con motivo (no se borra: si se borrara, la sincronizacion lo volveria
 * a crear porque el asociado sigue teniendo los puntos). Se sincronizan al consultar (pagina de
 * contenido del asociado y reporte del admin), asi que el flujo de pagos no cambia.
 * La fecha de obtencion se calcula con los movimientos de puntos (cuando el
 * acumulado llego a la meta).
 *
 * Usa mysqli: lo incluyen 05-tienda/php/admin.php y 04-contenido/php/conexion.php.
 */

if (!defined('PUNTOS_POR_BONO')) {
  define('PUNTOS_POR_BONO', 50);
}

if (!function_exists('asegurarTablaBonos')) {
  function asegurarTablaBonos(mysqli $conn): void
  {
    static $verificada = false;
    if ($verificada) return;

    $conn->query("CREATE TABLE IF NOT EXISTS bonos_puntos (
      id INT PRIMARY KEY AUTO_INCREMENT,
      usuario_id INT NOT NULL,
      numero INT NOT NULL,
      puntos_meta INT NOT NULL,
      fecha_obtenido DATETIME NOT NULL,
      estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
      fecha_entrega DATETIME NULL,
      nota VARCHAR(255) NULL,
      entregado_por VARCHAR(255) NULL,
      fecha_anulado DATETIME NULL,
      anulado_por VARCHAR(255) NULL,
      motivo_anulado VARCHAR(255) NULL,
      UNIQUE KEY uq_bonos_usuario_numero (usuario_id, numero),
      KEY idx_bonos_estado (estado, fecha_obtenido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tablas creadas antes de poder eliminar (anular) bonos
    $columna = $conn->query("SHOW COLUMNS FROM bonos_puntos LIKE 'motivo_anulado'");
    if ($columna && $columna->num_rows === 0) {
      $conn->query("ALTER TABLE bonos_puntos
        ADD COLUMN fecha_anulado DATETIME NULL,
        ADD COLUMN anulado_por VARCHAR(255) NULL,
        ADD COLUMN motivo_anulado VARCHAR(255) NULL");
    }

    $verificada = true;
  }
}

if (!function_exists('resumenBonosPuntos')) {
  /**
   * Calculo puro: bonos obtenidos y puntos que faltan para el siguiente.
   */
  function resumenBonosPuntos(int $puntos): array
  {
    $puntos = max(0, $puntos);
    $obtenidos = intdiv($puntos, PUNTOS_POR_BONO);
    $avance = $puntos % PUNTOS_POR_BONO;
    return [
      'puntos' => $puntos,
      'puntos_por_bono' => PUNTOS_POR_BONO,
      'bonos_obtenidos' => $obtenidos,
      'siguiente_bono' => $obtenidos + 1,
      'puntos_faltantes' => PUNTOS_POR_BONO - $avance,
      'porcentaje_siguiente' => (int)round($avance * 100 / PUNTOS_POR_BONO),
    ];
  }
}

if (!function_exists('sincronizarBonosUsuario')) {
  /**
   * Crea en bonos_puntos los bonos que el asociado ya alcanzo y aun no estan
   * registrados (idempotente: UNIQUE usuario_id + numero).
   */
  function sincronizarBonosUsuario(mysqli $conn, int $usuarioId, int $puntos): void
  {
    $obtenidos = intdiv(max(0, $puntos), PUNTOS_POR_BONO);
    if ($usuarioId <= 0 || $obtenidos === 0) return;
    asegurarTablaBonos($conn);

    $stmt = $conn->prepare("SELECT COALESCE(MAX(numero), 0) AS ultimo FROM bonos_puntos WHERE usuario_id = ?");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $ultimo = (int)$stmt->get_result()->fetch_assoc()['ultimo'];
    $stmt->close();
    if ($ultimo >= $obtenidos) return;

    // Fecha en que el acumulado alcanzo cada meta, segun los movimientos de puntos
    $fechas = [];
    $stmt = $conn->prepare("SELECT puntos, fecha_creacion FROM puntos_movimientos
                            WHERE usuario_id = ? AND puntos > 0 ORDER BY fecha_creacion, id");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $res = $stmt->get_result();
    $acumulado = 0;
    $siguiente = 1;
    while (($mov = $res->fetch_assoc()) && $siguiente <= $obtenidos) {
      $acumulado += (int)$mov['puntos'];
      while ($siguiente <= $obtenidos && $acumulado >= $siguiente * PUNTOS_POR_BONO) {
        $fechas[$siguiente++] = $mov['fecha_creacion'];
      }
    }
    $stmt->close();

    $insertar = $conn->prepare("INSERT IGNORE INTO bonos_puntos (usuario_id, numero, puntos_meta, fecha_obtenido)
                                VALUES (?, ?, ?, ?)");
    for ($n = $ultimo + 1; $n <= $obtenidos; $n++) {
      $meta = $n * PUNTOS_POR_BONO;
      // Sin movimientos suficientes (puntos cargados a mano): fecha de hoy
      $fecha = $fechas[$n] ?? date('Y-m-d H:i:s');
      $insertar->bind_param("iiis", $usuarioId, $n, $meta, $fecha);
      $insertar->execute();
    }
    $insertar->close();
  }
}

if (!function_exists('sincronizarBonosTodos')) {
  /**
   * Sincroniza a todos los asociados que tienen al menos un bono (reporte del admin).
   */
  function sincronizarBonosTodos(mysqli $conn): void
  {
    asegurarTablaBonos($conn);
    $res = $conn->query("SELECT usuario_id, puntos_acumulados FROM puntos_saldo WHERE puntos_acumulados >= " . (int)PUNTOS_POR_BONO);
    if (!$res) return;
    while ($fila = $res->fetch_assoc()) {
      sincronizarBonosUsuario($conn, (int)$fila['usuario_id'], (int)$fila['puntos_acumulados']);
    }
  }
}

if (!function_exists('bonosDelUsuario')) {
  /**
   * Resumen para la pagina de contenido: obtenidos, disponibles (pendientes de
   * entrega), entregados y puntos para el siguiente.
   */
  function bonosDelUsuario(mysqli $conn, int $usuarioId, int $puntos): array
  {
    $resumen = resumenBonosPuntos($puntos);
    $resumen['bonos_disponibles'] = 0;
    $resumen['bonos_entregados'] = 0;
    if ($resumen['bonos_obtenidos'] === 0) return $resumen;

    sincronizarBonosUsuario($conn, $usuarioId, $puntos);
    $stmt = $conn->prepare("SELECT estado, COUNT(*) AS total FROM bonos_puntos WHERE usuario_id = ? GROUP BY estado");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($fila = $res->fetch_assoc()) {
      if ($fila['estado'] === 'entregado') $resumen['bonos_entregados'] = (int)$fila['total'];
      elseif ($fila['estado'] === 'pendiente') $resumen['bonos_disponibles'] = (int)$fila['total'];
      // 'anulado': eliminado por el admin, no se muestra al asociado
    }
    $stmt->close();
    return $resumen;
  }
}

<?php

/**
 * Bonos por puntos acumulados
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Reglas:
 * - Cada puntosPorBono() puntos de saldo (puntos_saldo; cada compra aprobada da puntos
 *   al comprador y a sus dos antecesores) equivalen a un bono disponible. El admin lo
 *   configura (configuracion_tienda 'puntos_por_bono'); por defecto PUNTOS_POR_BONO.
 * - El bono vale puntosPorBono() x valor_punto_cop (configuracion_tienda) y se redime
 *   en una compra de la tienda: se descuenta del total del pedido, queda "entregado"
 *   (con el pedido) y se descuentan sus puntos del saldo, que vuelve a acumular desde
 *   lo que sobre.
 * - El admin tambien puede marcarlo entregado a mano o eliminarlo (estado 'anulado',
 *   con motivo); ambos descuentan los puntos. Deshacer / restaurar los devuelven.
 * - Si el pedido donde se uso el bono se cancela, el bono vuelve a "pendiente" y los
 *   puntos regresan (liberarBonosDePedidosCancelados).
 *
 * Cada bono tiene puntos_descontados (0/1) para no descontar ni devolver dos veces, y
 * puntos_meta: los puntos que descuenta y devuelve (los del bono, no el valor actual).
 * Los movimientos de puntos quedan en puntos_movimientos (tipos canje_bono,
 * anulacion_bono, reverso_bono).
 *
 * Usa mysqli: lo incluyen admin.php, pedidos.php, pagos.php,
 * cancelar_pedidos_abandonados.php y 04-contenido/php/conexion.php.
 */

if (!defined('PUNTOS_POR_BONO')) {
  define('PUNTOS_POR_BONO', 50);
}

if (!function_exists('puntosPorBono')) {
  /**
   * Puntos que forman un bono (configuracion_tienda 'puntos_por_bono', 1 a 10.000;
   * PUNTOS_POR_BONO si no esta definido). $refrescar tras cambiarlo en el admin.
   */
  function puntosPorBono(mysqli $conn, bool $refrescar = false): int
  {
    static $valor = null;
    if ($valor !== null && !$refrescar) return $valor;
    $valor = PUNTOS_POR_BONO;
    try {
      $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'puntos_por_bono' LIMIT 1");
      $fila = $res ? $res->fetch_assoc() : null;
      if ($fila && (int)$fila['valor'] >= 1 && (int)$fila['valor'] <= 10000) $valor = (int)$fila['valor'];
    } catch (Throwable $e) {
      // Sin tabla de configuracion: valor por defecto
    }
    return $valor;
  }
}

if (!function_exists('puntosDelBono')) {
  /** Puntos que descuenta / devuelve un bono: los que tenia al crearse (puntos_meta). */
  function puntosDelBono(mysqli $conn, array $bono): int
  {
    $meta = (int)($bono['puntos_meta'] ?? 0);
    return $meta > 0 ? $meta : puntosPorBono($conn);
  }
}

if (!class_exists('BonoException')) {
  /** Error de regla de negocio de bonos (mensaje apto para mostrar al usuario). */
  class BonoException extends Exception {}
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
      pedido_id INT NULL,
      valor_cop INT NULL,
      puntos_descontados TINYINT(1) NOT NULL DEFAULT 0,
      UNIQUE KEY uq_bonos_usuario_numero (usuario_id, numero),
      KEY idx_bonos_estado (estado, fecha_obtenido),
      KEY idx_bonos_pedido (pedido_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tablas creadas con versiones anteriores: agregar las columnas que falten
    $columnas = [
      'fecha_anulado' => 'DATETIME NULL',
      'anulado_por' => 'VARCHAR(255) NULL',
      'motivo_anulado' => 'VARCHAR(255) NULL',
      'pedido_id' => 'INT NULL',
      'valor_cop' => 'INT NULL',
      'puntos_descontados' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    $existentes = [];
    $res = $conn->query("SHOW COLUMNS FROM bonos_puntos");
    while ($fila = $res->fetch_assoc()) $existentes[$fila['Field']] = true;
    foreach ($columnas as $nombre => $definicion) {
      if (!isset($existentes[$nombre])) {
        $conn->query("ALTER TABLE bonos_puntos ADD COLUMN $nombre $definicion");
      }
    }

    // Descuento por bono en el pedido (0 = sin bono)
    $res = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'descuento_bono'");
    if ($res && $res->num_rows === 0) {
      $conn->query("ALTER TABLE pedidos ADD COLUMN descuento_bono DECIMAL(12,2) NOT NULL DEFAULT 0");
    }

    $verificada = true;
  }
}

if (!function_exists('valorBonoCop')) {
  /**
   * Valor en pesos de un bono: puntosPorBono() x valor del punto (0 = sin definir).
   */
  function valorBonoCop(mysqli $conn): int
  {
    try {
      $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'valor_punto_cop' LIMIT 1");
      $fila = $res ? $res->fetch_assoc() : null;
      return $fila ? max(0, (int)$fila['valor']) * puntosPorBono($conn) : 0;
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('saldoPuntosBono')) {
  function saldoPuntosBono(mysqli $conn, int $usuarioId): int
  {
    $stmt = $conn->prepare("SELECT puntos_acumulados FROM puntos_saldo WHERE usuario_id = ? LIMIT 1");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ? (int)$fila['puntos_acumulados'] : 0;
  }
}

if (!function_exists('moverPuntosBono')) {
  /**
   * Suma (positivo) o descuenta (negativo) puntos por un bono y deja el movimiento.
   */
  function moverPuntosBono(mysqli $conn, int $usuarioId, int $bonoId, int $puntos, string $tipo, string $descripcion): void
  {
    $clave = 'bono:' . $bonoId . ':' . $tipo . ':' . uniqid('', true);
    $stmt = $conn->prepare("INSERT INTO puntos_movimientos
        (usuario_id, pedido_id, pago_id, nivel, tipo, puntos, descripcion, metadata_json, clave_idempotencia)
      VALUES (?, NULL, NULL, 0, ?, ?, ?, ?, ?)");
    $metadata = json_encode(['bono_id' => $bonoId]);
    $stmt->bind_param("isisss", $usuarioId, $tipo, $puntos, $descripcion, $metadata, $clave);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO puntos_saldo (usuario_id, puntos_acumulados) VALUES (?, GREATEST(0, ?))
      ON DUPLICATE KEY UPDATE puntos_acumulados = GREATEST(0, puntos_acumulados + ?)");
    $stmt->bind_param("iii", $usuarioId, $puntos, $puntos);
    $stmt->execute();
    $stmt->close();
  }
}

if (!function_exists('sincronizarBonosUsuario')) {
  /**
   * Bonos pendientes = floor(saldo / puntosPorBono()): crea los que falten.
   * (Los entregados y eliminados ya descontaron sus puntos del saldo.)
   * Si el admin cambio los puntos por bono, los pendientes libres (sin pedido y sin
   * puntos descontados: solo reflejan el saldo) toman el valor nuevo y se borran los
   * que sobren (los de numero mas alto). Entregados, usados y eliminados no cambian.
   */
  function sincronizarBonosUsuario(mysqli $conn, int $usuarioId, int $saldo): void
  {
    if ($usuarioId <= 0) return;
    $porBono = puntosPorBono($conn);
    $disponibles = intdiv(max(0, $saldo), $porBono);
    asegurarTablaBonos($conn);

    $stmt = $conn->prepare("SELECT SUM(estado = 'pendiente') AS pendientes, COALESCE(MAX(numero), 0) AS ultimo,
                              SUM(estado = 'pendiente' AND puntos_meta <> ?) AS desactualizados
                            FROM bonos_puntos WHERE usuario_id = ?");
    $stmt->bind_param("ii", $porBono, $usuarioId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pendientes = (int)$fila['pendientes'];
    $numero = (int)$fila['ultimo'];

    if ((int)$fila['desactualizados'] > 0) {
      $stmt = $conn->prepare("UPDATE bonos_puntos SET puntos_meta = ?
                              WHERE usuario_id = ? AND estado = 'pendiente' AND pedido_id IS NULL
                                AND puntos_descontados = 0 AND puntos_meta <> ?");
      $stmt->bind_param("iii", $porBono, $usuarioId, $porBono);
      $stmt->execute();
      $stmt->close();
    }

    if ($pendientes > $disponibles) {
      $sobran = $pendientes - $disponibles;
      $stmt = $conn->prepare("DELETE FROM bonos_puntos
                              WHERE usuario_id = ? AND estado = 'pendiente' AND pedido_id IS NULL AND puntos_descontados = 0
                              ORDER BY numero DESC LIMIT ?");
      $stmt->bind_param("ii", $usuarioId, $sobran);
      $stmt->execute();
      $stmt->close();
      return;
    }
    if ($pendientes >= $disponibles) return;

    // Fecha de obtencion: el ultimo punto ganado
    $stmt = $conn->prepare("SELECT MAX(fecha_creacion) FROM puntos_movimientos WHERE usuario_id = ? AND puntos > 0");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $fecha = $stmt->get_result()->fetch_row()[0] ?: date('Y-m-d H:i:s');
    $stmt->close();

    $insertar = $conn->prepare("INSERT IGNORE INTO bonos_puntos (usuario_id, numero, puntos_meta, fecha_obtenido)
                                VALUES (?, ?, ?, ?)");
    $meta = $porBono;
    for ($i = $pendientes; $i < $disponibles; $i++) {
      $numero++;
      $insertar->bind_param("iiis", $usuarioId, $numero, $meta, $fecha);
      $insertar->execute();
    }
    $insertar->close();
  }
}

if (!function_exists('liberarBonosDePedidosCancelados')) {
  /**
   * Bonos usados en pedidos que terminaron cancelados: vuelven a "pendiente" y
   * los puntos regresan al asociado. Idempotente.
   */
  function liberarBonosDePedidosCancelados(mysqli $conn): int
  {
    asegurarTablaBonos($conn);
    $res = $conn->query("SELECT b.id FROM bonos_puntos b JOIN pedidos p ON p.id = b.pedido_id
                         WHERE b.estado = 'entregado' AND p.estado = 'cancelado'");
    $liberados = 0;
    while ($fila = $res->fetch_assoc()) {
      $conn->begin_transaction();
      try {
        $bono = bloquearBono($conn, (int)$fila['id']);
        if ($bono && $bono['estado'] === 'entregado' && $bono['pedido_id']) {
          $nota = 'Liberado: el pedido donde se usó fue cancelado';
          $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = 'pendiente', pedido_id = NULL, valor_cop = NULL,
                                    fecha_entrega = NULL, entregado_por = NULL, nota = ?, puntos_descontados = 0
                                  WHERE id = ?");
          $stmt->bind_param("si", $nota, $bono['id']);
          $stmt->execute();
          $stmt->close();
          if ((int)$bono['puntos_descontados'] === 1) {
            moverPuntosBono($conn, (int)$bono['usuario_id'], (int)$bono['id'], puntosDelBono($conn, $bono), 'reverso_bono', 'Bono liberado por pedido cancelado');
          }
          $liberados++;
        }
        $conn->commit();
      } catch (Throwable $e) {
        $conn->rollback();
        error_log('bonos: no se pudo liberar el bono ' . $fila['id'] . ': ' . $e->getMessage());
      }
    }
    return $liberados;
  }
}

if (!function_exists('sincronizarBonosTodos')) {
  /**
   * Libera bonos de pedidos cancelados y crea (o ajusta, si cambiaron los puntos por
   * bono) los bonos pendientes de cada asociado (reporte del admin).
   */
  function sincronizarBonosTodos(mysqli $conn): void
  {
    liberarBonosDePedidosCancelados($conn);
    $res = $conn->query("SELECT usuario_id, puntos_acumulados FROM puntos_saldo
                         WHERE puntos_acumulados >= " . puntosPorBono($conn) . "
                            OR usuario_id IN (SELECT usuario_id FROM bonos_puntos WHERE estado = 'pendiente')");
    if (!$res) return;
    while ($fila = $res->fetch_assoc()) {
      sincronizarBonosUsuario($conn, (int)$fila['usuario_id'], (int)$fila['puntos_acumulados']);
    }
  }
}

if (!function_exists('bloquearBono')) {
  /** Lee un bono con FOR UPDATE (llamar dentro de una transaccion). */
  function bloquearBono(mysqli $conn, int $bonoId): ?array
  {
    $stmt = $conn->prepare("SELECT * FROM bonos_puntos WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $bonoId);
    $stmt->execute();
    $bono = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $bono ?: null;
  }
}

if (!function_exists('cambiarEstadoBono')) {
  /**
   * Cambios de estado desde el admin, en una transaccion. Acciones:
   * - entregar (pendiente -> entregado, descuenta puntos)
   * - deshacer (entregado a mano -> pendiente, devuelve puntos)
   * - anular (pendiente/entregado a mano -> anulado, descuenta puntos si no estaban descontados)
   * - restaurar (anulado -> entregado si tenia entrega, si no pendiente devolviendo puntos)
   * @return string '' si se aplico, o el mensaje de error.
   */
  function cambiarEstadoBono(mysqli $conn, int $bonoId, string $accion, string $admin, string $texto): string
  {
    asegurarTablaBonos($conn);
    $conn->begin_transaction();
    try {
      $bono = bloquearBono($conn, $bonoId);
      if (!$bono) {
        $conn->rollback();
        return 'Bono no encontrado';
      }
      $uid = (int)$bono['usuario_id'];
      $descontados = (int)$bono['puntos_descontados'] === 1;
      $enPedido = !empty($bono['pedido_id']);
      $puntosBono = puntosDelBono($conn, $bono);

      if ($accion === 'entregar') {
        if ($bono['estado'] !== 'pendiente') throw new BonoException('Solo se puede entregar un bono pendiente');
        $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = 'entregado', fecha_entrega = NOW(), entregado_por = ?,
                                  nota = ?, puntos_descontados = 1 WHERE id = ?");
        $stmt->bind_param("ssi", $admin, $texto, $bonoId);
        $stmt->execute();
        $stmt->close();
        if (!$descontados) moverPuntosBono($conn, $uid, $bonoId, -$puntosBono, 'canje_bono', 'Bono #' . $bono['numero'] . ' entregado por el admin');
      } elseif ($accion === 'deshacer') {
        if ($bono['estado'] !== 'entregado') throw new BonoException('El bono no está entregado');
        if ($enPedido) throw new BonoException('Este bono se usó en un pedido: para devolverlo, cancela el pedido');
        $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = 'pendiente', fecha_entrega = NULL, entregado_por = NULL,
                                  nota = ?, puntos_descontados = 0 WHERE id = ?");
        $stmt->bind_param("si", $texto, $bonoId);
        $stmt->execute();
        $stmt->close();
        if ($descontados) moverPuntosBono($conn, $uid, $bonoId, $puntosBono, 'reverso_bono', 'Entrega del bono #' . $bono['numero'] . ' deshecha');
      } elseif ($accion === 'anular') {
        if ($bono['estado'] === 'anulado') throw new BonoException('El bono ya está eliminado');
        if ($enPedido) throw new BonoException('Este bono se usó en un pedido: para devolverlo, cancela el pedido');
        $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = 'anulado', fecha_anulado = NOW(), anulado_por = ?,
                                  motivo_anulado = ?, puntos_descontados = 1 WHERE id = ?");
        $stmt->bind_param("ssi", $admin, $texto, $bonoId);
        $stmt->execute();
        $stmt->close();
        if (!$descontados) moverPuntosBono($conn, $uid, $bonoId, -$puntosBono, 'anulacion_bono', 'Bono #' . $bono['numero'] . ' eliminado por el admin');
      } elseif ($accion === 'restaurar') {
        if ($bono['estado'] !== 'anulado') throw new BonoException('El bono no está eliminado');
        $aEntregado = !empty($bono['fecha_entrega']);
        $estado = $aEntregado ? 'entregado' : 'pendiente';
        $flag = $aEntregado ? 1 : 0;
        $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = ?, fecha_anulado = NULL, anulado_por = NULL,
                                  motivo_anulado = NULL, puntos_descontados = ? WHERE id = ?");
        $stmt->bind_param("sii", $estado, $flag, $bonoId);
        $stmt->execute();
        $stmt->close();
        if (!$aEntregado && $descontados) moverPuntosBono($conn, $uid, $bonoId, $puntosBono, 'reverso_bono', 'Bono #' . $bono['numero'] . ' restaurado');
      } else {
        throw new BonoException('Acción no válida');
      }

      $conn->commit();
      return '';
    } catch (BonoException $e) {
      $conn->rollback();
      return $e->getMessage();
    } catch (Throwable $e) {
      $conn->rollback();
      error_log('bonos cambiarEstadoBono: ' . $e->getMessage());
      return 'No se pudo actualizar el bono';
    }
  }
}

if (!function_exists('aplicarBonoAPedido')) {
  /**
   * Redime el bono pendiente mas antiguo del usuario en un pedido recien creado:
   * descuenta su valor del total (sin pasar de 0), lo marca entregado con el pedido
   * y descuenta sus puntos. Llamar DENTRO de la transaccion que crea el pedido.
   * @return array ['descuento' => float, 'bono_id' => int]
   * @throws BonoException si no hay bono disponible o no tiene valor definido
   */
  function aplicarBonoAPedido(mysqli $conn, int $usuarioId, int $pedidoId, float $totalPedido, string $email): array
  {
    asegurarTablaBonos($conn);
    sincronizarBonosUsuario($conn, $usuarioId, saldoPuntosBono($conn, $usuarioId));

    $valor = valorBonoCop($conn);
    if ($valor <= 0) throw new BonoException('El valor del bono aún no está definido. Intenta sin el bono.');

    $stmt = $conn->prepare("SELECT * FROM bonos_puntos WHERE usuario_id = ? AND estado = 'pendiente'
                            ORDER BY numero LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $bono = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bono) throw new BonoException('No tienes un bono disponible.');

    $descuento = (float)min($valor, $totalPedido);
    $valorAplicado = (int)round($descuento);
    $quien = 'Compra del asociado (' . $email . ')';
    $nota = 'Redimido en compra';
    $stmt = $conn->prepare("UPDATE bonos_puntos SET estado = 'entregado', fecha_entrega = NOW(), entregado_por = ?,
                              nota = ?, pedido_id = ?, valor_cop = ?, puntos_descontados = 1 WHERE id = ?");
    $stmt->bind_param("ssiii", $quien, $nota, $pedidoId, $valorAplicado, $bono['id']);
    $stmt->execute();
    $stmt->close();

    if ((int)$bono['puntos_descontados'] === 0) {
      moverPuntosBono($conn, $usuarioId, (int)$bono['id'], -puntosDelBono($conn, $bono), 'canje_bono', 'Bono #' . $bono['numero'] . ' redimido en compra');
    }

    $stmt = $conn->prepare("UPDATE pedidos SET descuento_bono = ?, total = GREATEST(0, total - ?) WHERE id = ?");
    $stmt->bind_param("ddi", $descuento, $descuento, $pedidoId);
    $stmt->execute();
    $stmt->close();

    return ['descuento' => $descuento, 'bono_id' => (int)$bono['id']];
  }
}

if (!function_exists('bonoDisponibleCheckout')) {
  /**
   * Para el checkout: cuantos bonos puede usar y cuanto vale cada uno.
   */
  function bonoDisponibleCheckout(mysqli $conn, int $usuarioId): array
  {
    try {
      asegurarTablaBonos($conn);
      liberarBonosDePedidosCancelados($conn);
      sincronizarBonosUsuario($conn, $usuarioId, saldoPuntosBono($conn, $usuarioId));
      $stmt = $conn->prepare("SELECT COUNT(*) FROM bonos_puntos WHERE usuario_id = ? AND estado = 'pendiente'");
      $stmt->bind_param("i", $usuarioId);
      $stmt->execute();
      $disponibles = (int)$stmt->get_result()->fetch_row()[0];
      $stmt->close();
      $valor = valorBonoCop($conn);
      return ['disponibles' => $disponibles, 'valor' => $valor];
    } catch (Throwable $e) {
      error_log('bonos checkout: ' . $e->getMessage());
      return ['disponibles' => 0, 'valor' => 0];
    }
  }
}

if (!function_exists('bonosDelUsuario')) {
  /**
   * Resumen para la pagina de contenido del asociado.
   */
  function bonosDelUsuario(mysqli $conn, int $usuarioId, int $saldo): array
  {
    // Saldo negativo (compra cancelada cuyos puntos ya se habian gastado): primero
    // debe recuperar esos puntos y luego juntar los del bono
    $deuda = max(0, -$saldo);
    $saldo = max(0, $saldo);
    $porBono = puntosPorBono($conn);
    $resumen = [
      'puntos' => $saldo - $deuda,
      'puntos_por_bono' => $porBono,
      'puntos_faltantes' => $porBono - ($saldo % $porBono) + $deuda,
      'porcentaje_siguiente' => $deuda > 0 ? 0 : (int)round(($saldo % $porBono) * 100 / $porBono),
      'puntos_por_recuperar' => $deuda,
      'bonos_disponibles' => 0,
      'bonos_entregados' => 0,
      'valor_bono' => 0,
      'ultimo_pedido' => '',
    ];

    asegurarTablaBonos($conn);
    liberarBonosDePedidosCancelados($conn);
    sincronizarBonosUsuario($conn, $usuarioId, $saldo);

    $stmt = $conn->prepare("SELECT estado, COUNT(*) AS total FROM bonos_puntos WHERE usuario_id = ? GROUP BY estado");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($fila = $res->fetch_assoc()) {
      if ($fila['estado'] === 'pendiente') $resumen['bonos_disponibles'] = (int)$fila['total'];
      elseif ($fila['estado'] === 'entregado') $resumen['bonos_entregados'] = (int)$fila['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT p.numero_pedido FROM bonos_puntos b JOIN pedidos p ON p.id = b.pedido_id
                            WHERE b.usuario_id = ? AND b.estado = 'entregado' ORDER BY b.fecha_entrega DESC LIMIT 1");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_row();
    $stmt->close();
    $resumen['ultimo_pedido'] = $fila ? (string)$fila[0] : '';
    $resumen['valor_bono'] = valorBonoCop($conn);
    return $resumen;
  }
}

if (!function_exists('resumenBonosPuntos')) {
  /** Calculo sin tablas de bonos (respaldo de 04-contenido); $porBono por defecto PUNTOS_POR_BONO. */
  function resumenBonosPuntos(int $saldo, int $porBono = PUNTOS_POR_BONO): array
  {
    $deuda = max(0, -$saldo);
    $saldo = max(0, $saldo);
    $porBono = max(1, $porBono);
    return [
      'puntos' => $saldo - $deuda,
      'puntos_por_bono' => $porBono,
      'puntos_faltantes' => $porBono - ($saldo % $porBono) + $deuda,
      'porcentaje_siguiente' => $deuda > 0 ? 0 : (int)round(($saldo % $porBono) * 100 / $porBono),
      'puntos_por_recuperar' => $deuda,
      'bonos_disponibles' => intdiv($saldo, $porBono),
      'bonos_entregados' => 0,
      'valor_bono' => 0,
      'ultimo_pedido' => '',
    ];
  }
}

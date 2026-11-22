<?php

/**
 * Reparto (split) de un pedido real: arma las entradas de calcularSplit() desde la BD y
 * guarda una copia congelada del reparto (paso 2 de SPLIT_PAGOS.md).
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * NO esta conectado al cobro ni a ningun flujo del cliente: solo lo usa admin.php para mostrar
 * "como se repartiria" un pedido (vista previa) y, si el admin lo pide, congelar ese reparto.
 * No mueve dinero ni cambia el estado, el stock, los puntos ni los bonos de ningun pedido.
 *
 * Usa mysqli ($conn). Incluye split-pagos-helpers.php (calculo puro), proveedores-helpers.php
 * (proveedores y % del referente) y puntos-pagos-helpers.php (referente del comprador).
 *
 * El proveedor nunca se expone al publico: estos datos son solo para el admin.
 */

require_once __DIR__ . '/split-pagos-helpers.php';
require_once __DIR__ . '/proveedores-helpers.php';
require_once __DIR__ . '/puntos-pagos-helpers.php';

if (!function_exists('asegurarTablaPedidoSplits')) {
  /**
   * Tabla con el reparto congelado de cada pedido (una fila por destinatario).
   * Se crea sola. Ejecutar ANTES de abrir transacciones (hace DDL).
   */
  function asegurarTablaPedidoSplits(mysqli $conn): void
  {
    static $verificada = false;
    if ($verificada) return;

    $conn->query("CREATE TABLE IF NOT EXISTS pedido_splits (
      id INT PRIMARY KEY AUTO_INCREMENT,
      pedido_id INT NOT NULL,
      actor_tipo VARCHAR(20) NOT NULL,
      actor_id INT NOT NULL DEFAULT 0,
      nombre VARCHAR(150) NOT NULL DEFAULT '',
      monto BIGINT NOT NULL,
      descripcion VARCHAR(160) NOT NULL DEFAULT '',
      estado VARCHAR(20) NOT NULL DEFAULT 'calculado',
      fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_pedido_split_actor (pedido_id, actor_tipo, actor_id),
      KEY idx_pedido_splits_pedido (pedido_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verificada = true;
  }
}

if (!function_exists('calcularSplitDePedido')) {
  /**
   * Calcula el reparto de un pedido con los datos ACTUALES de la BD (porcentajes de los
   * proveedores y del referente de hoy). Solo lectura.
   *
   * @return array ['pedido' => fila de pedidos, 'lineas' => lineas de calcularSplit(),
   *                'porcentaje_referente' => float, 'referente' => array|null,
   *                'notas' => string[]]
   * @throws SplitException si el pedido no existe o el reparto no cuadra
   */
  function calcularSplitDePedido(mysqli $conn, int $pedidoId): array
  {
    asegurarTablasProveedores($conn);

    $stmt = $conn->prepare(
      "SELECT p.id, p.numero_pedido, p.usuario_id, p.estado, p.metodo_pago, p.subtotal,
              p.costo_envio, p.total, r.email AS comprador_email
       FROM pedidos p
       LEFT JOIN registros r ON r.id = p.usuario_id
       WHERE p.id = ?"
    );
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pedido) {
      throw new SplitException('Pedido no encontrado');
    }

    // Productos del pedido con su proveedor (0 = Tienda (propio))
    $stmt = $conn->prepare(
      "SELECT dp.producto_id, dp.subtotal, COALESCE(pp.proveedor_id, 0) AS proveedor_id
       FROM detalle_pedido dp
       LEFT JOIN producto_proveedor pp ON pp.producto_id = dp.producto_id
       WHERE dp.pedido_id = ?"
    );
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    $idsProveedores = [];
    while ($fila = $res->fetch_assoc()) {
      $items[] = [
        'producto_id' => (int)$fila['producto_id'],
        'subtotal' => (int)round((float)$fila['subtotal']),
        'proveedor_id' => (int)$fila['proveedor_id'],
      ];
      if ((int)$fila['proveedor_id'] > 0) {
        $idsProveedores[(int)$fila['proveedor_id']] = true;
      }
    }
    $stmt->close();

    // Proveedores involucrados con su % acordado
    $proveedores = [];
    foreach (array_keys($idsProveedores) as $proveedorId) {
      $prov = proveedorPorId($conn, (int)$proveedorId);
      if ($prov) {
        $proveedores[(int)$proveedorId] = [
          'nombre' => (string)$prov['nombre'],
          'porcentaje_proveedor' => (float)$prov['porcentaje_proveedor'],
        ];
      }
    }

    // Referente directo del comprador (nivel 1) y su % global
    $referente = null;
    $porcentajeReferente = porcentajeReferente($conn);
    $emailComprador = (string)($pedido['comprador_email'] ?? '');
    if ($emailComprador !== '') {
      $ascendientes = obtenerAscendientesVerticales($conn, $emailComprador, 1);
      if (!empty($ascendientes[0])) {
        $asc = $ascendientes[0];
        $referente = [
          'usuario_id' => (int)$asc['usuario_id'],
          'nombre' => trim($asc['nombres'] . ' ' . $asc['apellidos']),
        ];
      }
    }

    $lineas = calcularSplit(
      [
        'total' => (int)round((float)$pedido['total']),
        'subtotal' => (int)round((float)$pedido['subtotal']),
        'costo_envio' => (int)round((float)$pedido['costo_envio']),
      ],
      $items,
      $proveedores,
      $referente,
      $porcentajeReferente
      // Sin mensajeria: todavia no existe esa figura (Fase 2), el envio queda en la tienda
    );

    $notas = [];
    if ($referente === null) {
      $notas[] = 'El comprador no tiene referente: ese porcentaje queda en la tienda.';
    }
    if ((int)round((float)$pedido['costo_envio']) > 0) {
      $notas[] = 'El envio queda en la tienda (aun no hay mensajeria registrada).';
    }

    return [
      'pedido' => $pedido,
      'lineas' => $lineas,
      'porcentaje_referente' => $porcentajeReferente,
      'referente' => $referente,
      'notas' => $notas,
    ];
  }
}

if (!function_exists('splitGuardadoDePedido')) {
  /** Reparto congelado de un pedido (lista de lineas) o [] si todavia no se guardo. */
  function splitGuardadoDePedido(mysqli $conn, int $pedidoId): array
  {
    asegurarTablaPedidoSplits($conn);
    $stmt = $conn->prepare(
      "SELECT actor_tipo, actor_id, nombre, monto, descripcion, estado, fecha_creacion
       FROM pedido_splits WHERE pedido_id = ? ORDER BY id"
    );
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $lineas = [];
    while ($fila = $res->fetch_assoc()) {
      $fila['actor_id'] = (int)$fila['actor_id'];
      $fila['monto'] = (int)$fila['monto'];
      $lineas[] = $fila;
    }
    $stmt->close();
    return $lineas;
  }
}

if (!function_exists('guardarSplitDePedido')) {
  /**
   * Congela el reparto de un pedido con los porcentajes de hoy. Idempotente: si el pedido ya
   * tiene reparto guardado NO lo cambia (un cambio de % solo aplica a ventas nuevas).
   *
   * @return array ['guardado_ahora' => bool, 'lineas' => lineas guardadas]
   * @throws SplitException si el pedido no existe o el reparto no cuadra
   */
  function guardarSplitDePedido(mysqli $conn, int $pedidoId): array
  {
    asegurarTablaPedidoSplits($conn);

    $existente = splitGuardadoDePedido($conn, $pedidoId);
    if ($existente) {
      return ['guardado_ahora' => false, 'lineas' => $existente];
    }

    $calculo = calcularSplitDePedido($conn, $pedidoId);

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare(
        "INSERT IGNORE INTO pedido_splits (pedido_id, actor_tipo, actor_id, nombre, monto, descripcion)
         VALUES (?, ?, ?, ?, ?, ?)"
      );
      foreach ($calculo['lineas'] as $linea) {
        $tipo = (string)$linea['actor_tipo'];
        $actorId = (int)$linea['actor_id'];
        $nombre = mb_substr((string)$linea['nombre'], 0, 150);
        $monto = (int)$linea['monto'];
        $descripcion = mb_substr((string)$linea['descripcion'], 0, 160);
        $stmt->bind_param('isisis', $pedidoId, $tipo, $actorId, $nombre, $monto, $descripcion);
        $stmt->execute();
      }
      $stmt->close();
      $conn->commit();
    } catch (Throwable $e) {
      $conn->rollback();
      throw $e;
    }

    return ['guardado_ahora' => true, 'lineas' => splitGuardadoDePedido($conn, $pedidoId)];
  }
}

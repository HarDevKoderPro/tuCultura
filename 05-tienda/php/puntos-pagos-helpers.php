<?php

/**
 * Helpers base para pagos y puntos
 *
 * Este archivo NO ejecuta cambios por si solo.
 * Debe incluirse explicitamente desde los endpoints que lo necesiten.
 */

if (!function_exists('obtenerAscendientesVerticales')) {
  /**
   * Obtiene hasta N ascendientes verticales por cadena de referente.
   *
   * Ejemplo para N=2:
   * comprador -> referente (nivel 1) -> referente del referente (nivel 2)
   */
  function obtenerAscendientesVerticales($conn, $emailComprador, $maxNiveles = 2)
  {
    $ascendientes = [];
    $emailActual = trim((string)$emailComprador);

    if ($emailActual === '' || $maxNiveles < 1) {
      return $ascendientes;
    }

    $visitados = [strtolower($emailActual) => true];

    for ($nivel = 1; $nivel <= $maxNiveles; $nivel++) {
      $stmtRef = $conn->prepare("SELECT referente FROM registros WHERE email = ? LIMIT 1");
      $stmtRef->bind_param("s", $emailActual);
      $stmtRef->execute();
      $rowRef = $stmtRef->get_result()->fetch_assoc();
      $stmtRef->close();

      if (!$rowRef || empty($rowRef['referente'])) {
        break;
      }

      $emailReferente = trim((string)$rowRef['referente']);
      $emailReferenteKey = strtolower($emailReferente);

      if ($emailReferente === '' || isset($visitados[$emailReferenteKey])) {
        break;
      }

      $stmtUser = $conn->prepare("SELECT id, email, nombres, apellidos FROM registros WHERE email = ? LIMIT 1");
      $stmtUser->bind_param("s", $emailReferente);
      $stmtUser->execute();
      $usuario = $stmtUser->get_result()->fetch_assoc();
      $stmtUser->close();

      if (!$usuario) {
        break;
      }

      $ascendientes[] = [
        'nivel' => $nivel,
        'usuario_id' => (int)$usuario['id'],
        'email' => (string)$usuario['email'],
        'nombres' => (string)($usuario['nombres'] ?? ''),
        'apellidos' => (string)($usuario['apellidos'] ?? ''),
      ];

      $visitados[$emailReferenteKey] = true;
      $emailActual = $emailReferente;
    }

    return $ascendientes;
  }
}

if (!function_exists('crearClaveIdempotenciaPuntos')) {
  /**
   * Crea una clave unica por contexto de asignacion.
   */
  function crearClaveIdempotenciaPuntos($pedidoId, $usuarioId, $nivel, $tipo)
  {
    return implode('|', [
      'pedido:' . (int)$pedidoId,
      'usuario:' . (int)$usuarioId,
      'nivel:' . (int)$nivel,
      'tipo:' . trim((string)$tipo),
    ]);
  }
}

if (!function_exists('obtenerSaldoPuntosUsuario')) {
  function obtenerSaldoPuntosUsuario($conn, $usuarioId)
  {
    $uid = (int)$usuarioId;
    if ($uid <= 0) {
      return 0;
    }

    $stmt = $conn->prepare("SELECT puntos_acumulados FROM puntos_saldo WHERE usuario_id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['puntos_acumulados'] : 0;
  }
}

if (!function_exists('sumarPuntosSaldo')) {
  function sumarPuntosSaldo($conn, $usuarioId, $puntos = 1)
  {
    $uid = (int)$usuarioId;
    $pts = (int)$puntos;

    if ($uid <= 0 || $pts <= 0) {
      return false;
    }

    $stmt = $conn->prepare(
      "INSERT INTO puntos_saldo (usuario_id, puntos_acumulados)
       VALUES (?, ?)
       ON DUPLICATE KEY UPDATE puntos_acumulados = puntos_acumulados + VALUES(puntos_acumulados)"
    );
    $stmt->bind_param("ii", $uid, $pts);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
  }
}

if (!function_exists('registrarMovimientoPuntos')) {
  /**
   * Inserta un movimiento con idempotencia por clave unica.
   */
  function registrarMovimientoPuntos(
    $conn,
    $usuarioId,
    $pedidoId,
    $pagoId,
    $nivel,
    $tipo,
    $puntos,
    $descripcion,
    $metadataJson,
    $claveIdempotencia
  ) {
    $uid = (int)$usuarioId;
    $pid = $pedidoId !== null ? (int)$pedidoId : null;
    $payId = $pagoId !== null ? (int)$pagoId : null;
    $lvl = (int)$nivel;
    $pts = (int)$puntos;
    $tipo = trim((string)$tipo);
    $descripcion = trim((string)$descripcion);
    $metadata = $metadataJson !== null ? (string)$metadataJson : null;
    $clave = trim((string)$claveIdempotencia);

    if ($uid <= 0 || $pts <= 0 || $tipo === '' || $clave === '') {
      return false;
    }

    $sql = "INSERT INTO puntos_movimientos
              (usuario_id, pedido_id, pago_id, nivel, tipo, puntos, descripcion, metadata_json, clave_idempotencia)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    // i i i i s i s s s
    $stmt->bind_param(
      "iiiisisss",
      $uid,
      $pid,
      $payId,
      $lvl,
      $tipo,
      $pts,
      $descripcion,
      $metadata,
      $clave
    );

    $ok = $stmt->execute();
    $errorCode = $stmt->errno;
    $stmt->close();

    // 1062 = duplicate key (idempotencia); se considera exito logico.
    if (!$ok && $errorCode === 1062) {
      return true;
    }

    return $ok;
  }
}

/*
 * Puntos por rangos del valor de la compra.
 * Se guardan en configuracion_tienda (clave 'puntos_rangos') como JSON compacto
 * [[desde, hasta, puntos], ...]; hasta = null es "sin limite" (solo el ultimo).
 * La columna valor es VARCHAR(255): por eso el maximo de rangos y de montos.
 */
if (!defined('PUNTOS_RANGOS_MAXIMO')) {
  define('PUNTOS_RANGOS_MAXIMO', 8);
  define('PUNTOS_RANGOS_MONTO_MAXIMO', 999999999);
  define('PUNTOS_RANGOS_PUNTOS_MAXIMO', 1000);
}

if (!function_exists('validarRangosPuntos')) {
  /**
   * Valida y normaliza los rangos recibidos del admin.
   * Acepta filas {desde, hasta, puntos} o [desde, hasta, puntos]; hasta vacio = sin limite.
   * Devuelve ['rangos' => [...]] ordenados por desde, o ['error' => mensaje].
   */
  function validarRangosPuntos($entrada)
  {
    if (!is_array($entrada)) {
      return ['error' => 'Formato de rangos no válido'];
    }
    if (count($entrada) > PUNTOS_RANGOS_MAXIMO) {
      return ['error' => 'Se permiten máximo ' . PUNTOS_RANGOS_MAXIMO . ' rangos'];
    }

    $entero = function ($v) {
      $v = trim(str_replace(['.', ',', '$', ' '], '', (string)$v));
      return preg_match('/^\d{1,10}$/', $v) ? (int)$v : null;
    };

    $rangos = [];
    foreach (array_values($entrada) as $i => $fila) {
      $n = $i + 1;
      if (!is_array($fila)) {
        return ['error' => "Rango $n: formato no válido"];
      }
      $desde = $fila['desde'] ?? ($fila[0] ?? null);
      $hasta = $fila['hasta'] ?? ($fila[1] ?? null);
      $puntos = $fila['puntos'] ?? ($fila[2] ?? null);

      $desde = $entero($desde);
      if ($desde === null || $desde > PUNTOS_RANGOS_MONTO_MAXIMO) {
        return ['error' => "Rango $n: \"Desde\" debe ser un valor en pesos entre 0 y 999.999.999"];
      }
      if ($hasta === null || trim((string)$hasta) === '') {
        $hasta = null;
      } else {
        $hasta = $entero($hasta);
        if ($hasta === null || $hasta > PUNTOS_RANGOS_MONTO_MAXIMO) {
          return ['error' => "Rango $n: \"Hasta\" debe ser un valor en pesos hasta 999.999.999, o vacío para sin límite"];
        }
        if ($hasta < $desde) {
          return ['error' => "Rango $n: \"Hasta\" no puede ser menor que \"Desde\""];
        }
      }
      $puntos = $entero($puntos);
      if ($puntos === null || $puntos > PUNTOS_RANGOS_PUNTOS_MAXIMO) {
        return ['error' => "Rango $n: los puntos deben ser un número entero entre 0 y " . PUNTOS_RANGOS_PUNTOS_MAXIMO];
      }
      $rangos[] = [$desde, $hasta, $puntos];
    }

    usort($rangos, function ($a, $b) {
      return $a[0] <=> $b[0];
    });

    $total = count($rangos);
    for ($i = 0; $i < $total; $i++) {
      if ($rangos[$i][1] === null && $i < $total - 1) {
        return ['error' => 'Solo el último rango (el de mayor valor) puede quedar sin límite'];
      }
      if ($i > 0 && $rangos[$i][0] <= $rangos[$i - 1][1]) {
        return ['error' => 'Los rangos se cruzan: revise el que empieza en $' . number_format($rangos[$i][0], 0, ',', '.')];
      }
    }

    return ['rangos' => $rangos];
  }
}

if (!function_exists('rangosPuntosCompra')) {
  /**
   * Rangos configurados [[desde, hasta, puntos], ...] ([] si no hay o el valor guardado no es valido).
   */
  function rangosPuntosCompra($conn)
  {
    try {
      $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'puntos_rangos' LIMIT 1");
      $fila = $res ? $res->fetch_assoc() : null;
    } catch (Throwable $e) {
      return [];
    }
    if (!$fila) {
      return [];
    }
    $validacion = validarRangosPuntos(json_decode($fila['valor'], true));
    return $validacion['rangos'] ?? [];
  }
}

/*
 * Puntos por compra (antes en pagos.php; aqui para que tambien los use el admin
 * al marcar un pedido entregado o cancelado).
 * - Se dan al comprador (nivel 0) y a sus referentes de nivel 1 y 2, la misma
 *   cantidad para cada uno segun el rango del valor de la compra.
 * - Una vez por pedido: antes de dar puntos se mira el neto de los movimientos del
 *   pedido (compra - reversos). Si el pedido se cancela se revierten (el saldo
 *   puede quedar negativo) y si se reactiva se pueden volver a dar, una vez.
 * Llamar dentro de una transaccion con el pedido bloqueado (FOR UPDATE).
 */
if (!defined('PUNTOS_POR_COMPRA')) {
  // Puntos por compra aprobada fuera de los rangos: comprador y ascendientes (nivel 1 y 2)
  define('PUNTOS_POR_COMPRA', 1);
  define('PUNTOS_NIVELES_REFERIDOS', 2);
}

if (!function_exists('puntosPorValorCompra')) {
  /**
   * Puntos que da una compra segun el valor de sus productos (sin envio y antes
   * del bono) y los rangos del admin. Fuera de los rangos: PUNTOS_POR_COMPRA.
   * Devuelve ['puntos' => int, 'valor_productos' => float, 'rango' => [desde, hasta, puntos] | null].
   */
  function puntosPorValorCompra($conn, $pedidoId)
  {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(subtotal), 0) AS valor FROM detalle_pedido WHERE pedido_id = ?");
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $valor = (float)$stmt->get_result()->fetch_assoc()['valor'];
    $stmt->close();

    foreach (rangosPuntosCompra($conn) as $rango) {
      if ($valor >= $rango[0] && ($rango[1] === null || $valor <= $rango[1])) {
        return ['puntos' => (int)$rango[2], 'valor_productos' => $valor, 'rango' => $rango];
      }
    }
    return ['puntos' => PUNTOS_POR_COMPRA, 'valor_productos' => $valor, 'rango' => null];
  }
}

if (!function_exists('netoPuntosCompraPedido')) {
  /**
   * Puntos netos de compra de un pedido por beneficiario: [usuario_id|nivel => ['neto', 'reversos']].
   */
  function netoPuntosCompraPedido($conn, $pedidoId)
  {
    $stmt = $conn->prepare(
      "SELECT usuario_id, nivel, SUM(puntos) AS neto, SUM(tipo = 'reverso_compra') AS reversos
       FROM puntos_movimientos
       WHERE pedido_id = ? AND tipo IN ('compra_propia', 'compra_referido', 'reverso_compra')
       GROUP BY usuario_id, nivel"
    );
    $stmt->bind_param('i', $pedidoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $netos = [];
    while ($fila = $res->fetch_assoc()) {
      $netos[(int)$fila['usuario_id'] . '|' . (int)$fila['nivel']] = [
        'usuario_id' => (int)$fila['usuario_id'],
        'nivel' => (int)$fila['nivel'],
        'neto' => (int)$fila['neto'],
        'reversos' => (int)$fila['reversos'],
      ];
    }
    $stmt->close();
    return $netos;
  }
}

if (!function_exists('asignarPuntosCompra')) {
  /**
   * Puntos al comprador y a sus referentes de nivel 1 y 2 (la misma cantidad para
   * cada uno, segun el rango del valor de la compra; ver puntosPorValorCompra).
   * Idempotente: quien ya tiene puntos netos de este pedido no recibe mas, y cada
   * movimiento tiene clave unica (la primera vez 'compra'; tras un reverso, 'compra:N').
   * Un rango de 0 puntos no registra movimientos.
   */
  function asignarPuntosCompra($conn, $pedido, $pagoLocalId)
  {
    $pedidoId = (int)$pedido['id'];
    $compradorId = (int)$pedido['usuario_id'];

    $calculo = puntosPorValorCompra($conn, $pedidoId);
    $puntos = $calculo['puntos'];
    if ($puntos <= 0) {
      return [];
    }

    $stmt = $conn->prepare("SELECT email FROM registros WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $compradorId);
    $stmt->execute();
    $comprador = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $beneficiarios = [['nivel' => 0, 'usuario_id' => $compradorId, 'tipo' => 'compra_propia']];
    if ($comprador) {
      foreach (obtenerAscendientesVerticales($conn, $comprador['email'], PUNTOS_NIVELES_REFERIDOS) as $asc) {
        $beneficiarios[] = ['nivel' => $asc['nivel'], 'usuario_id' => $asc['usuario_id'], 'tipo' => 'compra_referido'];
      }
    }

    $netos = netoPuntosCompraPedido($conn, $pedidoId);
    $pagoId = $pagoLocalId !== null ? (int)$pagoLocalId : null;

    $stmtMov = $conn->prepare(
      "INSERT INTO puntos_movimientos
         (usuario_id, pedido_id, pago_id, nivel, tipo, puntos, descripcion, metadata_json, clave_idempotencia)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE id = id"
    );

    $asignados = [];
    foreach ($beneficiarios as $b) {
      $uid = (int)$b['usuario_id'];
      $nivel = (int)$b['nivel'];
      $previo = $netos[$uid . '|' . $nivel] ?? ['neto' => 0, 'reversos' => 0];
      if ($previo['neto'] > 0) {
        continue;
      }
      $tipo = $b['tipo'];
      $descripcion = $nivel === 0
        ? 'Compra propia, pedido ' . $pedido['numero_pedido']
        : 'Compra de referido nivel ' . $nivel . ', pedido ' . $pedido['numero_pedido'];
      $metadata = json_encode([
        'comprador_id' => $compradorId,
        'numero_pedido' => $pedido['numero_pedido'],
        'valor_productos' => $calculo['valor_productos'],
        'rango' => $calculo['rango']
      ]);
      $clave = crearClaveIdempotenciaPuntos($pedidoId, $uid, $nivel, $previo['reversos'] > 0 ? 'compra:' . ($previo['reversos'] + 1) : 'compra');

      $stmtMov->bind_param('iiiisisss', $uid, $pedidoId, $pagoId, $nivel, $tipo, $puntos, $descripcion, $metadata, $clave);
      $stmtMov->execute();

      // affected_rows = 1 solo si el movimiento es nuevo
      if ($stmtMov->affected_rows === 1) {
        sumarPuntosSaldo($conn, $uid, $puntos);
        $asignados[] = ['usuario_id' => $uid, 'nivel' => $nivel, 'puntos' => $puntos];
      }
    }
    $stmtMov->close();

    return $asignados;
  }
}

if (!function_exists('revertirPuntosCompra')) {
  /**
   * Pedido cancelado: quita a cada beneficiario los puntos netos que le dio el pedido
   * (movimiento 'reverso_compra'). El saldo puede quedar negativo si ya los gasto en un bono.
   */
  function revertirPuntosCompra($conn, $pedido)
  {
    $pedidoId = (int)$pedido['id'];
    $stmtMov = $conn->prepare(
      "INSERT INTO puntos_movimientos
         (usuario_id, pedido_id, pago_id, nivel, tipo, puntos, descripcion, metadata_json, clave_idempotencia)
       VALUES (?, ?, NULL, ?, 'reverso_compra', ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE id = id"
    );
    $stmtSaldo = $conn->prepare(
      "INSERT INTO puntos_saldo (usuario_id, puntos_acumulados) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE puntos_acumulados = puntos_acumulados + VALUES(puntos_acumulados)"
    );

    $revertidos = [];
    foreach (netoPuntosCompraPedido($conn, $pedidoId) as $n) {
      if ($n['neto'] <= 0) {
        continue;
      }
      $uid = $n['usuario_id'];
      $nivel = $n['nivel'];
      $puntos = -$n['neto'];
      $descripcion = 'Reverso por pedido cancelado ' . $pedido['numero_pedido'];
      $metadata = json_encode(['numero_pedido' => $pedido['numero_pedido']]);
      $clave = crearClaveIdempotenciaPuntos($pedidoId, $uid, $nivel, 'reverso:' . ($n['reversos'] + 1));

      $stmtMov->bind_param('iiiisss', $uid, $pedidoId, $nivel, $puntos, $descripcion, $metadata, $clave);
      $stmtMov->execute();
      if ($stmtMov->affected_rows === 1) {
        $stmtSaldo->bind_param('ii', $uid, $puntos);
        $stmtSaldo->execute();
        $revertidos[] = ['usuario_id' => $uid, 'nivel' => $nivel, 'puntos' => $puntos];
      }
    }
    $stmtMov->close();
    $stmtSaldo->close();

    return $revertidos;
  }
}

if (!function_exists('sincronizarPuntosPedido')) {
  /**
   * Aplica la regla de puntos segun el estado del pedido ($pedido: id, numero_pedido,
   * usuario_id, estado, metodo_pago), despues de cambiarlo:
   * - cancelado: se revierten los puntos que haya dado.
   * - con pago de MercadoPago aprobado (compra o enlace de cobro), o contra entrega /
   *   transferencia marcado "entregado": se dan (si aun no los tiene).
   * Devuelve ['asignados' => [...], 'revertidos' => [...]].
   */
  function sincronizarPuntosPedido($conn, $pedido)
  {
    $resultado = ['asignados' => [], 'revertidos' => []];
    if ($pedido['estado'] === 'cancelado') {
      $resultado['revertidos'] = revertirPuntosCompra($conn, $pedido);
      return $resultado;
    }

    $pago = function_exists('pagoAprobadoPedido') ? pagoAprobadoPedido($conn, (string)$pedido['numero_pedido']) : null;
    $entregadoSinPasarela = $pedido['estado'] === 'entregado'
      && in_array($pedido['metodo_pago'], ['contraentrega', 'transferencia'], true);

    if ($pago || $entregadoSinPasarela) {
      $resultado['asignados'] = asignarPuntosCompra($conn, $pedido, $pago ? (int)$pago['id'] : null);
    }
    return $resultado;
  }
}

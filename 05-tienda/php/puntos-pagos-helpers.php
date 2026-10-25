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

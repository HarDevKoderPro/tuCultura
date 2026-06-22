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

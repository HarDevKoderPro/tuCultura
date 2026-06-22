<?php

/**
 * Migracion base para pagos (MercadoPago) y sistema de puntos.
 *
 * Ejecutar una sola vez:
 * /05-tienda/php/migrar_pagos_puntos.php
 */

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Migracion: pagos + puntos</h2>";

function ejecutarSQL($conn, $sql, $okMsg, $errorPrefix)
{
  if ($conn->query($sql)) {
    echo "<p style='color:green;'>" . $okMsg . "</p>";
    return true;
  }

  echo "<p style='color:red;'>" . $errorPrefix . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8') . "</p>";
  return false;
}

$sqlPagos = "CREATE TABLE IF NOT EXISTS pagos_tienda (
  id INT PRIMARY KEY AUTO_INCREMENT,
  pedido_id INT NULL,
  usuario_id INT NOT NULL,
  metodo_pago VARCHAR(30) NOT NULL DEFAULT 'mercadopago',
  estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
  preference_id VARCHAR(120) NULL,
  payment_id VARCHAR(120) NULL,
  external_reference VARCHAR(120) NULL,
  idempotency_key VARCHAR(150) NULL,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  moneda VARCHAR(10) NOT NULL DEFAULT 'COP',
  detalle_estado VARCHAR(120) NULL,
  payload_json JSON NULL,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pagos_payment_id (payment_id),
  UNIQUE KEY uk_pagos_preference_id (preference_id),
  UNIQUE KEY uk_pagos_idempotency (idempotency_key),
  KEY idx_pagos_usuario (usuario_id),
  KEY idx_pagos_pedido (pedido_id),
  KEY idx_pagos_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sqlSaldo = "CREATE TABLE IF NOT EXISTS puntos_saldo (
  usuario_id INT PRIMARY KEY,
  puntos_acumulados INT NOT NULL DEFAULT 0,
  fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_puntos_saldo_puntos (puntos_acumulados)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sqlMovimientos = "CREATE TABLE IF NOT EXISTS puntos_movimientos (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  usuario_id INT NOT NULL,
  pedido_id INT NULL,
  pago_id INT NULL,
  nivel TINYINT NOT NULL DEFAULT 0,
  tipo VARCHAR(40) NOT NULL,
  puntos INT NOT NULL,
  descripcion VARCHAR(255) NULL,
  metadata_json JSON NULL,
  clave_idempotencia VARCHAR(180) NOT NULL,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_puntos_idempotencia (clave_idempotencia),
  KEY idx_puntos_usuario (usuario_id),
  KEY idx_puntos_pedido (pedido_id),
  KEY idx_puntos_pago (pago_id),
  KEY idx_puntos_tipo (tipo),
  KEY idx_puntos_fecha (fecha_creacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$okPagos = ejecutarSQL(
  $conn,
  $sqlPagos,
  "✅ Tabla <strong>pagos_tienda</strong> creada/verificada.",
  "❌ Error creando pagos_tienda: "
);

$okSaldo = ejecutarSQL(
  $conn,
  $sqlSaldo,
  "✅ Tabla <strong>puntos_saldo</strong> creada/verificada.",
  "❌ Error creando puntos_saldo: "
);

$okMovs = ejecutarSQL(
  $conn,
  $sqlMovimientos,
  "✅ Tabla <strong>puntos_movimientos</strong> creada/verificada.",
  "❌ Error creando puntos_movimientos: "
);

if ($okPagos && $okSaldo && $okMovs) {
  echo "<hr>";
  echo "<p><strong>Migracion completada.</strong></p>";
  echo "<ul>";
  echo "<li>pagos_tienda: tracking e idempotencia de pagos.</li>";
  echo "<li>puntos_saldo: acumulado de puntos por usuario.</li>";
  echo "<li>puntos_movimientos: auditoria de asignaciones.</li>";
  echo "</ul>";
}

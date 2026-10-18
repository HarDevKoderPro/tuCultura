<?php

/**
 * Cancela pedidos de MercadoPago abandonados (nunca pagados).
 *
 * Solo CLI. En el VPS lo ejecuta el cron del usuario deploy:
 *   docker exec tucultura-app-1 php /var/www/html/05-tienda/php/cancelar_pedidos_abandonados.php
 *
 * Se cancela un pedido "pendiente" con metodo mercadopago cuando:
 * - tiene mas de HORAS_ABANDONO horas y su ultimo intento de pago no esta
 *   aprobado ni en proceso (la preferencia vence a las 48 h, ver pagos.php), o
 * - tiene mas de DIAS_MAXIMO dias y no tiene un pago aprobado (pagos en efectivo
 *   que quedaron "pending" porque no llego el aviso de vencimiento).
 * Como el stock solo se descuenta al aprobar el pago, cancelar no lo modifica.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

const HORAS_ABANDONO = 72;
const DIAS_MAXIMO = 10;

$conn = new mysqli(
  getenv('DB_HOST') ?: 'localhost',
  getenv('DB_USER') ?: 'tucultur',
  getenv('DB_PASSWORD') ?: '',
  getenv('DB_NAME') ?: 'tucultur_asociados'
);
$conn->set_charset('utf8mb4');

$sql = "UPDATE pedidos p
        LEFT JOIN pagos_tienda pt ON pt.id = (
          -- pagos_tienda y pedidos tienen collations distintas en la BD
          SELECT MAX(id) FROM pagos_tienda WHERE external_reference = p.numero_pedido COLLATE utf8mb4_unicode_ci
        )
        SET p.estado = 'cancelado'
        WHERE p.estado = 'pendiente'
          AND p.metodo_pago = 'mercadopago'
          AND (
            (p.fecha_creacion < NOW() - INTERVAL ? HOUR
              AND COALESCE(pt.estado, '') NOT IN ('approved', 'authorized', 'pending', 'in_process'))
            OR
            (p.fecha_creacion < NOW() - INTERVAL ? DAY
              AND COALESCE(pt.estado, '') <> 'approved')
          )";

$horas = HORAS_ABANDONO;
$dias = DIAS_MAXIMO;
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $horas, $dias);
$stmt->execute();

echo date('Y-m-d H:i:s') . ' Pedidos MercadoPago abandonados cancelados: ' . $stmt->affected_rows . PHP_EOL;

// Bonos por puntos usados en pedidos cancelados: vuelven al asociado con sus puntos
require_once __DIR__ . '/bonos-helpers.php';
$liberados = liberarBonosDePedidosCancelados($conn);
if ($liberados > 0) {
  echo date('Y-m-d H:i:s') . ' Bonos liberados de pedidos cancelados: ' . $liberados . PHP_EOL;
}

<?php
/**
 * Tipo de producto (fisico / digital) en carrito, checkout y pedidos.
 *
 * Reglas (opcion A del plan de productos digitales):
 * - Un digital no usa stock (se trata como ilimitado y nunca se descuenta), ni envio, ni direccion.
 * - El envio y el envio gratis se calculan solo con los productos FISICOS del carrito.
 * - Si el carrito trae algun digital, solo se aceptan pagos anticipados (no contra entrega).
 *
 * Todo funciona aunque la columna productos.tipo_producto aun no exista (la agrega el
 * admin al usar el tipo): en ese caso todos los productos son fisicos. Las consultas de
 * lectura NUNCA hacen ALTER TABLE (haria commit implicito dentro de una transaccion);
 * solo asegurarColumnaTipoProducto() lo hace y debe llamarse fuera de transacciones.
 *
 * Usa mysqli ($conn). Lo incluyen carrito.php, pedidos.php, pagos.php y admin.php.
 */

const STOCK_DIGITAL_ILIMITADO = 999999;

function existeColumnaTipoProducto(mysqli $conn, bool $volverAComprobar = false): bool
{
  static $existe = null;
  if ($existe === null || $volverAComprobar) {
    $r = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'");
    $existe = (bool)($r && $r->num_rows > 0);
  }
  return $existe;
}

/** Agrega la columna si falta (DDL: no llamar dentro de una transaccion). */
function asegurarColumnaTipoProducto(mysqli $conn): void
{
  if (existeColumnaTipoProducto($conn)) return;
  $conn->query("ALTER TABLE productos ADD COLUMN tipo_producto VARCHAR(10) NOT NULL DEFAULT 'fisico'");
  existeColumnaTipoProducto($conn, true);
}

function normalizarTipoProducto($valor)
{
  return $valor === 'digital' ? 'digital' : 'fisico';
}

/** Expresion SQL del tipo de un producto ('fisico' si la columna aun no existe). */
function exprTipoProducto(mysqli $conn, string $prefijo = 'p.'): string
{
  return existeColumnaTipoProducto($conn) ? $prefijo . 'tipo_producto' : "'fisico'";
}

/** Expresion SQL del stock: ilimitado para los digitales. */
function exprStockEfectivo(mysqli $conn, string $prefijo = 'p.'): string
{
  if (!existeColumnaTipoProducto($conn)) return $prefijo . 'stock';
  return "IF({$prefijo}tipo_producto = 'digital', " . STOCK_DIGITAL_ILIMITADO . ", {$prefijo}stock)";
}

/**
 * Costo de envio: solo cuenta el subtotal de los productos fisicos (0 si no hay fisicos).
 */
function costoEnvioFisicos(float $subtotalFisico): float
{
  if ($subtotalFisico <= 0) return 0.0;
  return $subtotalFisico >= TIENDA_ENVIO_GRATIS_MINIMO ? 0.0 : (float)TIENDA_COSTO_ENVIO_BASE;
}

/**
 * Cuantos productos fisicos y digitales hay en el carrito de un usuario.
 * Devuelve ['total' => n, 'fisicos' => n, 'digitales' => n, 'solo_digital' => bool].
 */
function resumenTiposCarritoUsuario(mysqli $conn, int $usuarioId): array
{
  $tipo = exprTipoProducto($conn, 'p.');
  $stmt = $conn->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM($tipo = 'digital'), 0) AS digitales
     FROM carrito c JOIN productos p ON c.producto_id = p.id
     WHERE c.usuario_id = ? AND p.activo = 1"
  );
  $stmt->bind_param('i', $usuarioId);
  $stmt->execute();
  $fila = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $total = (int)($fila['total'] ?? 0);
  $digitales = (int)($fila['digitales'] ?? 0);
  return [
    'total' => $total,
    'fisicos' => $total - $digitales,
    'digitales' => $digitales,
    'solo_digital' => $total > 0 && $digitales === $total
  ];
}

/**
 * Datos de envio que se guardan cuando el pedido es solo de productos digitales.
 */
function datosEnvioDigital(): array
{
  return [
    'direccion' => 'Producto digital (sin envío)',
    'ciudad' => '-',
    'departamento' => '-',
    'codigo_postal' => ''
  ];
}

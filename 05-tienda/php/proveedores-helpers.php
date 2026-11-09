<?php
/**
 * Proveedores de productos y reparto de cada venta (paso 1: solo datos del admin).
 *
 * Regla del negocio: los productos de terceros se muestran como propios de la tienda.
 * La relacion producto -> proveedor vive en una tabla aparte (producto_proveedor) para
 * que ninguna consulta publica que use productos.* la exponga. Solo admin.php la lee.
 *
 * Reparto de una venta: proveedor = su % acordado; referente del comprador = % global
 * (configuracion_tienda.porcentaje_referente, por defecto 10); tienda = el resto. Sin
 * referente, ese % queda para la tienda. Sin proveedor ("Tienda (propio)") el proveedor es 0%.
 *
 * Usa mysqli ($conn). Lo incluye admin.php.
 */

const PORCENTAJE_REFERENTE_DEFECTO = 10;

function asegurarTablasProveedores(mysqli $conn): void
{
  static $verificada = false;
  if ($verificada) return;

  $conn->query("CREATE TABLE IF NOT EXISTS proveedores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    documento VARCHAR(30) NOT NULL DEFAULT '',
    contacto VARCHAR(120) NOT NULL DEFAULT '',
    telefono VARCHAR(30) NOT NULL DEFAULT '',
    email VARCHAR(150) NOT NULL DEFAULT '',
    porcentaje_proveedor DECIMAL(5,2) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS producto_proveedor (
    producto_id INT PRIMARY KEY,
    proveedor_id INT NOT NULL,
    fecha_asignacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_producto_proveedor_proveedor (proveedor_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $verificada = true;
}

/**
 * % del referente del comprador (0-100). Sin definir = PORCENTAJE_REFERENTE_DEFECTO.
 */
function porcentajeReferente(mysqli $conn): float
{
  $valor = null;
  try {
    $stmt = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = 'porcentaje_referente'");
    if ($stmt && $stmt->execute()) {
      $fila = $stmt->get_result()->fetch_assoc();
      if ($fila) $valor = $fila['valor'];
    }
  } catch (Throwable $e) {
    $valor = null;
  }
  if ($valor === null || !is_numeric($valor)) return (float)PORCENTAJE_REFERENTE_DEFECTO;
  return max(0.0, min(100.0, (float)$valor));
}

/**
 * Valida un porcentaje recibido por POST: numero de 0 a 100 con hasta 2 decimales.
 * Devuelve el valor o null si no es valido.
 */
function normalizarPorcentaje($entrada): ?float
{
  $texto = str_replace(',', '.', trim((string)$entrada));
  if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $texto)) return null;
  $valor = (float)$texto;
  return ($valor >= 0 && $valor <= 100) ? $valor : null;
}

function proveedorPorId(mysqli $conn, int $id): ?array
{
  asegurarTablasProveedores($conn);
  $stmt = $conn->prepare("SELECT * FROM proveedores WHERE id = ?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $fila = $stmt->get_result()->fetch_assoc();
  return $fila ?: null;
}

/**
 * Proveedor asignado a un producto (0 = Tienda (propio)).
 */
function proveedorDeProducto(mysqli $conn, int $productoId): int
{
  asegurarTablasProveedores($conn);
  $stmt = $conn->prepare("SELECT proveedor_id FROM producto_proveedor WHERE producto_id = ?");
  $stmt->bind_param('i', $productoId);
  $stmt->execute();
  $fila = $stmt->get_result()->fetch_assoc();
  return $fila ? (int)$fila['proveedor_id'] : 0;
}

/**
 * Asigna (o quita con 0) el proveedor de un producto.
 */
function asignarProveedorProducto(mysqli $conn, int $productoId, int $proveedorId): bool
{
  asegurarTablasProveedores($conn);
  if ($proveedorId <= 0) {
    $stmt = $conn->prepare("DELETE FROM producto_proveedor WHERE producto_id = ?");
    $stmt->bind_param('i', $productoId);
    return $stmt->execute();
  }
  $stmt = $conn->prepare("INSERT INTO producto_proveedor (producto_id, proveedor_id) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE proveedor_id = VALUES(proveedor_id)");
  $stmt->bind_param('ii', $productoId, $proveedorId);
  return $stmt->execute();
}

/**
 * Valida el proveedor_id que llega en el formulario de producto.
 * Devuelve el id a guardar (0 = propio) o un mensaje de error (string).
 * Un proveedor inactivo solo se acepta si el producto ya lo tenia asignado.
 */
function validarProveedorParaProducto(mysqli $conn, $entrada, int $productoId = 0)
{
  $proveedorId = intval($entrada);
  if ($proveedorId <= 0) return 0;

  $proveedor = proveedorPorId($conn, $proveedorId);
  if (!$proveedor) return 'El proveedor elegido no existe';
  if (!(int)$proveedor['activo'] && proveedorDeProducto($conn, $productoId) !== $proveedorId) {
    return 'El proveedor elegido esta desactivado';
  }
  return $proveedorId;
}

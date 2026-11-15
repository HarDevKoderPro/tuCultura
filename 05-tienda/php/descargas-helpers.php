<?php
/**
 * Descarga de productos digitales (paso 3).
 *
 * Regla: el comprador (dueno del pedido, con sesion) puede descargar los archivos de los
 * productos digitales de SU pedido mientras el pedido tenga el pago aprobado, es decir,
 * mientras su estado NO sea 'pendiente' ni 'cancelado':
 *   - MercadoPago: el pedido pasa a 'confirmado' cuando el pago se aprueba.
 *   - Transferencia: el admin lo pasa a 'confirmado' al verificar el pago.
 * Se evalua en cada descarga: cancelar el pedido revoca el acceso al instante.
 *
 * Los archivos viven fuera de la carpeta publica (archivos-digitales.php) y solo
 * php/descargar.php los entrega. Usa PDO ($pdo). Lo incluyen pedidos.php y descargar.php.
 */

const ESTADOS_PEDIDO_SIN_DESCARGA = ['pendiente', 'cancelado'];

function pedidoPermiteDescargas(string $estado): bool
{
  return !in_array($estado, ESTADOS_PEDIDO_SIN_DESCARGA, true);
}

function pdoTieneColumnaTipoProducto(PDO $pdo): bool
{
  static $existe = null;
  if ($existe === null) {
    $existe = (bool)$pdo->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'")->fetch();
  }
  return $existe;
}

function asegurarTablaDescargas(PDO $pdo): void
{
  static $verificada = false;
  if ($verificada) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS descargas_producto (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    pedido_id INT NOT NULL,
    archivo_id INT NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_descargas_pedido (pedido_id),
    KEY idx_descargas_usuario (usuario_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $verificada = true;
}

function urlDescargaArchivo(int $pedidoId, int $archivoId): string
{
  return 'php/descargar.php?pedido=' . $pedidoId . '&archivo=' . $archivoId;
}

/**
 * Productos digitales de un pedido con sus archivos:
 * [producto_id => [['id', 'nombre', 'etiqueta', 'tamano_texto', 'url'], ...]].
 * Un producto digital sin archivos subidos aparece con lista vacia.
 */
function archivosDigitalesDePedido(PDO $pdo, int $pedidoId): array
{
  if (!pdoTieneColumnaTipoProducto($pdo)) return [];

  $stmt = $pdo->prepare(
    "SELECT DISTINCT d.producto_id
     FROM detalle_pedido d
     JOIN productos p ON p.id = d.producto_id
     WHERE d.pedido_id = ? AND p.tipo_producto = 'digital'"
  );
  $stmt->execute([$pedidoId]);

  $resultado = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $productoId) {
    $archivos = [];
    foreach (listarArchivosDigitales($pdo, (int)$productoId) as $a) {
      $archivos[] = [
        'id' => $a['id'],
        'nombre' => $a['nombre'],
        'etiqueta' => $a['etiqueta'],
        'tamano_texto' => $a['tamano_texto'],
        'url' => urlDescargaArchivo($pedidoId, $a['id'])
      ];
    }
    $resultado[(int)$productoId] = $archivos;
  }
  return $resultado;
}

/**
 * Fila de producto_archivo si pertenece a un producto digital de ese pedido (o null).
 */
function archivoDescargableDePedido(PDO $pdo, int $pedidoId, int $archivoId): ?array
{
  asegurarTablaArchivosDigitales($pdo);
  if (!pdoTieneColumnaTipoProducto($pdo)) return null;

  $stmt = $pdo->prepare(
    "SELECT a.id, a.producto_id, a.nombre_original, a.archivo, a.tamano
     FROM producto_archivo a
     JOIN detalle_pedido d ON d.producto_id = a.producto_id AND d.pedido_id = ?
     JOIN productos p ON p.id = a.producto_id
     WHERE a.id = ? AND p.tipo_producto = 'digital'
     LIMIT 1"
  );
  $stmt->execute([$pedidoId, $archivoId]);
  $fila = $stmt->fetch(PDO::FETCH_ASSOC);
  return $fila ?: null;
}

function registrarDescarga(PDO $pdo, int $usuarioId, int $pedidoId, int $archivoId): void
{
  try {
    asegurarTablaDescargas($pdo);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $pdo->prepare("INSERT INTO descargas_producto (usuario_id, pedido_id, archivo_id, ip) VALUES (?, ?, ?, ?)")
        ->execute([$usuarioId, $pedidoId, $archivoId, $ip]);
  } catch (Throwable $e) {
    // El registro es solo auditoria: nunca debe impedir la descarga
    error_log('descargas: no se pudo registrar la descarga: ' . $e->getMessage());
  }
}

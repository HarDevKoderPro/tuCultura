<?php

/**
 * Optimizacion de fotos de productos (WebP).
 *
 * La usan admin.php al subir una foto y optimizar_imagenes_productos.php para
 * las fotos existentes. Requiere la extension GD con soporte WebP (Dockerfile).
 * El archivo original nunca se borra: si la optimizacion no es posible, se
 * sigue usando tal cual.
 */

const IMAGEN_PRODUCTO_LADO_MAX = 1200;
const IMAGEN_PRODUCTO_CALIDAD = 82;
// Un WebP ya liviano y de tamano razonable no se vuelve a comprimir
const IMAGEN_PRODUCTO_WEBP_OK_BYTES = 200 * 1024;

/**
 * Genera una version WebP optimizada (lado mayor de IMAGEN_PRODUCTO_LADO_MAX px)
 * de una foto guardada en $carpeta.
 *
 * @return string Nombre del WebP generado, o '' si no se genero (sin GD/WebP,
 *                formato no soportado o el resultado no es mas liviano).
 */
function optimizarImagenProducto(string $carpeta, string $nombre): string
{
  if (!function_exists('imagewebp')) {
    return '';
  }

  $carpeta = rtrim($carpeta, '/\\') . '/';
  $origen = $carpeta . $nombre;
  $info = @getimagesize($origen);
  if ($info === false || !is_file($origen)) {
    return '';
  }

  [$ancho, $alto, $tipo] = $info;
  $pesoOrigen = filesize($origen);

  // GIF (posible animacion) y AVIF se dejan como estan
  switch ($tipo) {
    case IMAGETYPE_JPEG:
      $imagen = @imagecreatefromjpeg($origen);
      break;
    case IMAGETYPE_PNG:
      $imagen = @imagecreatefrompng($origen);
      break;
    case IMAGETYPE_WEBP:
      if (max($ancho, $alto) <= IMAGEN_PRODUCTO_LADO_MAX && $pesoOrigen <= IMAGEN_PRODUCTO_WEBP_OK_BYTES) {
        return '';
      }
      $imagen = @imagecreatefromwebp($origen);
      break;
    default:
      return '';
  }
  if (!$imagen) {
    return '';
  }

  // Fotos de celular: respetar la orientacion EXIF
  if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $exif = @exif_read_data($origen);
    $giro = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
    if ($giro !== 0) {
      $girada = imagerotate($imagen, $giro, 0);
      if ($girada) {
        imagedestroy($imagen);
        $imagen = $girada;
        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];
      }
    }
  }

  $escala = min(1, IMAGEN_PRODUCTO_LADO_MAX / max($ancho, $alto));
  $nuevoAncho = max(1, (int) round($ancho * $escala));
  $nuevoAlto = max(1, (int) round($alto * $escala));

  // Lienzo con canal alfa para conservar transparencias de PNG/WebP
  $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
  imagealphablending($destino, false);
  imagesavealpha($destino, true);
  imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
  imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
  imagedestroy($imagen);

  $base = pathinfo($nombre, PATHINFO_FILENAME);
  $nombreWebp = $base . ($tipo === IMAGETYPE_WEBP ? '-opt' : '') . '.webp';
  $rutaWebp = $carpeta . $nombreWebp;

  $ok = imagewebp($destino, $rutaWebp, IMAGEN_PRODUCTO_CALIDAD);
  imagedestroy($destino);
  clearstatcache(true, $rutaWebp);

  if (!$ok || !is_file($rutaWebp) || filesize($rutaWebp) >= $pesoOrigen) {
    @unlink($rutaWebp);
    return '';
  }
  @chmod($rutaWebp, 0664);
  return $nombreWebp;
}

// ==================== FOTOS ADICIONALES DE PRODUCTOS ====================
// La foto principal sigue en productos.imagen; las demas van en producto_imagenes
// (en el orden en que se suben). La tienda las muestra como tira de miniaturas.

const IMAGENES_PRODUCTO_MAX_ADICIONALES = 10;

function asegurarTablaImagenesProducto(PDO $pdo): void
{
  static $verificada = false;
  if ($verificada) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS producto_imagenes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    producto_id INT NOT NULL,
    imagen VARCHAR(255) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_producto_imagenes_producto (producto_id, orden)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $verificada = true;
}

/**
 * Fotos adicionales de un producto: [['id' => .., 'imagen' => ..], ...]
 */
function listarImagenesAdicionales(PDO $pdo, int $productoId): array
{
  asegurarTablaImagenesProducto($pdo);
  $stmt = $pdo->prepare("SELECT id, imagen FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id");
  $stmt->execute([$productoId]);
  return array_map(function ($fila) {
    return ['id' => (int)$fila['id'], 'imagen' => $fila['imagen']];
  }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Todas las fotos del producto para la tienda: principal primero y luego las adicionales.
 */
function imagenesProductoTienda(PDO $pdo, int $productoId, ?string $principal): array
{
  $imagenes = [];
  if (!empty($principal)) {
    $imagenes[] = $principal;
  }
  foreach (listarImagenesAdicionales($pdo, $productoId) as $fila) {
    if ($fila['imagen'] !== $principal) {
      $imagenes[] = $fila['imagen'];
    }
  }
  return $imagenes;
}

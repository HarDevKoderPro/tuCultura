<?php

/**
 * Enlace para compartir un producto en redes sociales
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * producto.html se arma con JavaScript y los robots de WhatsApp, Facebook, X o
 * Telegram no lo ejecutan, asi que no verian ni el nombre ni la foto. Esta pagina
 * entrega las etiquetas Open Graph del producto y lleva a las personas a
 * producto.html?id=...
 *
 * - compartir.php?id=N          pagina con la vista previa (redirige al producto)
 * - compartir.php?id=N&ref=COD  igual, con el codigo de invitacion del asociado que
 *                               compartio (invitaciones-helpers.php); pasa a producto.html
 * - compartir.php?id=N&imagen=1 foto del producto en JPEG (no todas las redes
 *                               muestran WebP en la vista previa)
 */

require_once 'config.php';
require_once 'invitaciones-helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT id, nombre, descripcion_corta, descripcion, precio, precio_oferta, imagen
                       FROM productos WHERE id = ? AND activo = 1");
$stmt->execute([$id]);
$producto = $stmt->fetch();

$rutaImagenDefecto = __DIR__ . '/../../01-principal/imagenes/fondo-producto.png';

if (isset($_GET['imagen'])) {
  entregarImagenJpeg($producto, $rutaImagenDefecto);
  exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
$basePublic = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_URL, '/');

if (!$producto) {
  // Producto inexistente o inactivo: a la tienda
  header('Location: ' . $basePublic . '/index.html', true, 302);
  exit;
}

$ref = normalizarCodigoInvitacion($_GET['ref'] ?? '');
$sufijoRef = $ref !== '' ? '&ref=' . $ref : '';

$urlProducto  = $basePublic . '/producto.html?id=' . $producto['id'] . $sufijoRef;
$urlCompartir = $basePublic . '/php/compartir.php?id=' . $producto['id'] . $sufijoRef;
$urlImagen    = $basePublic . '/php/compartir.php?id=' . $producto['id'] . '&imagen=1';

$tieneOferta = (float)$producto['precio_oferta'] > 0 && (float)$producto['precio_oferta'] < (float)$producto['precio'];
$precio = formatearPrecio($tieneOferta ? $producto['precio_oferta'] : $producto['precio']);

$texto = trim(strip_tags($producto['descripcion_corta'] ?: $producto['descripcion'] ?: ''));
$texto = preg_replace('/\s+/u', ' ', $texto);
if (mb_strlen($texto) > 160) {
  $texto = rtrim(mb_substr($texto, 0, 157)) . '...';
}
$descripcion = $precio . ($tieneOferta ? ' (oferta)' : '') . ($texto !== '' ? ' - ' . $texto : '');

$titulo = $producto['nombre'] . ' | Tu Cultura es Progreso';

function h($valor)
{
  return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titulo) ?></title>
    <meta name="description" content="<?= h($descripcion) ?>">
    <link rel="canonical" href="<?= h($urlProducto) ?>">

    <meta property="og:type" content="product">
    <meta property="og:site_name" content="Tu Cultura es Progreso">
    <meta property="og:locale" content="es_CO">
    <meta property="og:title" content="<?= h($titulo) ?>">
    <meta property="og:description" content="<?= h($descripcion) ?>">
    <meta property="og:url" content="<?= h($urlCompartir) ?>">
    <meta property="og:image" content="<?= h($urlImagen) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:alt" content="<?= h($producto['nombre']) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($titulo) ?>">
    <meta name="twitter:description" content="<?= h($descripcion) ?>">
    <meta name="twitter:image" content="<?= h($urlImagen) ?>">

    <meta http-equiv="refresh" content="0; url=<?= h($urlProducto) ?>">
    <script>location.replace(<?= json_encode($urlProducto) ?>);</script>
</head>
<body>
    <p><a href="<?= h($urlProducto) ?>">Ver <?= h($producto['nombre']) ?> en la tienda</a></p>
</body>
</html>
<?php

/**
 * Entrega la foto del producto como JPEG (fondo blanco si tenia transparencia).
 * Si no hay GD o la foto no existe, entrega la imagen por defecto tal cual.
 */
function entregarImagenJpeg($producto, $rutaImagenDefecto)
{
  $ruta = $rutaImagenDefecto;
  if ($producto && !empty($producto['imagen'])) {
    $candidata = __DIR__ . '/../imagenes/productos/' . basename($producto['imagen']);
    if (is_file($candidata)) {
      $ruta = $candidata;
    }
  }

  header('Cache-Control: public, max-age=86400');

  $origen = function_exists('imagecreatefromstring') ? @imagecreatefromstring(file_get_contents($ruta)) : false;
  if (!$origen) {
    $tipo = function_exists('mime_content_type') ? mime_content_type($ruta) : 'image/png';
    header('Content-Type: ' . $tipo);
    readfile($ruta);
    return;
  }

  $ancho = imagesx($origen);
  $alto = imagesy($origen);
  $lienzo = imagecreatetruecolor($ancho, $alto);
  imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
  imagecopy($lienzo, $origen, 0, 0, 0, 0, $ancho, $alto);

  header('Content-Type: image/jpeg');
  imagejpeg($lienzo, null, 85);
}

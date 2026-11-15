<?php
/**
 * Descarga protegida de un archivo de producto digital.
 * GET php/descargar.php?pedido=ID&archivo=ID
 *
 * Responde con el archivo (no JSON) solo si: hay sesion de cliente, el pedido es suyo,
 * su pago esta aprobado (no 'pendiente' ni 'cancelado') y el archivo pertenece a un
 * producto digital de ese pedido. Ver descargas-helpers.php.
 */

require_once 'config.php';
require_once 'archivos-digitales.php';
require_once 'descargas-helpers.php';

function terminarDescarga(int $codigo, string $titulo, string $mensaje): void
{
  http_response_code($codigo);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
  $m = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
  echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $t . '</title></head>'
    . '<body style="font-family:Arial,sans-serif;max-width:520px;margin:15vh auto;padding:0 16px;text-align:center;color:#333">'
    . '<h2 style="color:#023859">' . $t . '</h2><p>' . $m . '</p>'
    . '<p><a href="../mis-pedidos.html" style="color:#1d4f9c;font-weight:bold">Ir a Mis pedidos</a></p>'
    . '</body></html>';
  exit;
}

// Sin sesion (p. ej. enlace abierto desde un correo): se lleva al login y luego a Mis pedidos
if (!usuarioLogueado()) {
  header('Location: ../../02-iniciarSesion/iniciarSesion.html?origen=tienda&returnUrl=mis-pedidos.html');
  exit;
}
if (!esClienteLogueado()) {
  terminarDescarga(403, 'No disponible', 'Las cuentas de administrador no tienen compras para descargar.');
}

$pedidoId = isset($_GET['pedido']) ? (int)$_GET['pedido'] : 0;
$archivoId = isset($_GET['archivo']) ? (int)$_GET['archivo'] : 0;
if ($pedidoId <= 0 || $archivoId <= 0) {
  terminarDescarga(400, 'Enlace no válido', 'El enlace de descarga está incompleto.');
}

$usuarioId = (int)obtenerUsuarioId($conn, $_SESSION['email']);
$stmt = $pdo->prepare("SELECT id, estado FROM pedidos WHERE id = ? AND usuario_id = ?");
$stmt->execute([$pedidoId, $usuarioId]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pedido) {
  terminarDescarga(404, 'Pedido no encontrado', 'No encontramos ese pedido en tu cuenta.');
}

if (!pedidoPermiteDescargas((string)$pedido['estado'])) {
  $mensaje = $pedido['estado'] === 'cancelado'
    ? 'Este pedido fue cancelado, por eso la descarga ya no está disponible.'
    : 'La descarga estará disponible cuando tu pago esté aprobado. Te avisaremos en Mis pedidos.';
  terminarDescarga(403, 'Descarga no disponible', $mensaje);
}

$archivo = archivoDescargableDePedido($pdo, $pedidoId, $archivoId);
if (!$archivo) {
  terminarDescarga(404, 'Archivo no encontrado', 'Este archivo no pertenece a tu pedido.');
}

$ruta = carpetaArchivosDigitales() . '/' . $archivo['archivo'];
if (!preg_match('/^[a-f0-9]{32}$/', $archivo['archivo']) || !is_file($ruta) || !is_readable($ruta)) {
  error_log('descargar: archivo faltante en disco para producto_archivo ' . $archivoId);
  terminarDescarga(404, 'Archivo no disponible', 'No pudimos encontrar el archivo en este momento. Escríbenos y lo resolvemos.');
}

registrarDescarga($pdo, $usuarioId, $pedidoId, $archivoId);

// Nombre para el comprador: version ASCII de respaldo + UTF-8 (RFC 5987)
$nombre = (string)$archivo['nombre_original'];
$respaldo = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: 'archivo');
if ($respaldo === '' || $respaldo === null) $respaldo = 'archivo';

// La sesion no debe quedar bloqueada mientras se transmite el archivo
session_write_close();
while (ob_get_level() > 0) {
  ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $respaldo . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$f = fopen($ruta, 'rb');
if ($f === false) {
  exit;
}
while (!feof($f)) {
  echo fread($f, 1048576);
  flush();
}
fclose($f);
exit;

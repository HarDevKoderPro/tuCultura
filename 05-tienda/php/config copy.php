<?php

/**
 * Configuración del módulo de tienda
 * Tu Cultura es Progreso - Tienda Virtual
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ✅ Detección automática de entorno (compatible con localhost, 127.0.0.1 y servidor real)
$esLocal = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1']);

if ($esLocal) {
  // ==== LOCAL ====
  $host     = 'localhost';
  $dbname   = 'tucultur_asociados';
  $username = 'tucultur';
  $password = '@GWMU!J4p-mgyTJ7';
  define('BASE_URL', '/PRUEBAS/tuCulturaNew/05-tienda/');  // ⚠️ Ajusta según tu carpeta real en XAMPP/WAMP
  define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/PRUEBAS/tuCulturaNew/05-tienda/imagenes/productos/');
} else {
  // ==== SERVIDOR REAL ====
  $host     = "190.8.176.115";
  $dbname   = 'tucultur_asociados';
  $username = 'tucultur';
  $password = '@GWMU!J4p-mgyTJ7';
  define('BASE_URL', '/05-tienda/');
  define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/05-tienda/imagenes/productos/');
}

// Conexión
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $conn->connect_error]);
  exit;
}
$conn->set_charset("utf8mb4");

// ✅ DEBUG_MODE automático: true en local, false en servidor
define('DEBUG_MODE', $esLocal);

define('TIENDA_NOMBRE', 'Tu Cultura es Progreso - Tienda');
define('TIENDA_MONEDA', 'COP');
define('TIENDA_SIMBOLO_MONEDA', '$');
define('TIENDA_IVA', 0);
define('TIENDA_COSTO_ENVIO_BASE', 15000);
define('TIENDA_ENVIO_GRATIS_MINIMO', 20000);

$admins = ['luisfer5428@gmail.com', 'haroldvaldes@yahoo.com'];

function sanitizar($input)
{
  if (is_array($input)) return array_map('sanitizar', $input);
  return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
function formatearPrecio($precio)
{
  return TIENDA_SIMBOLO_MONEDA . ' ' . number_format($precio, 0, ',', '.');
}
function generarNumeroPedido()
{
  return 'TCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}
function usuarioLogueado()
{
  return isset($_SESSION['email']) && !empty($_SESSION['email']);
}
function obtenerUsuarioId($conn, $email)
{
  $stmt = $conn->prepare("SELECT id FROM registros WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) return $row['id'];
  return null;
}
function obtenerDatosUsuario($conn, $email)
{
  $stmt = $conn->prepare("SELECT * FROM registros WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  return $stmt->get_result()->fetch_assoc();
}
function esAdmin()
{
  global $admins;
  return isset($_SESSION['email']) && in_array($_SESSION['email'], $admins);
}
function esEmailAdmin($email)
{
  global $admins;
  return in_array($email, $admins);
}
function jsonResponse($data, $statusCode = 200)
{
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function getSessionId()
{
  if (!isset($_SESSION['cart_session_id'])) {
    $_SESSION['cart_session_id'] = session_id() . '_' . time();
  }
  return $_SESSION['cart_session_id'];
}

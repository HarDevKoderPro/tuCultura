<?php
session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['error' => 'No autorizado']);
  exit;
}

include './conexion.php';

$nivel = isset($_GET['nivel']) ? intval($_GET['nivel']) : 1;
$email = $_SESSION['email'];

switch ($nivel) {
  case 1:
    $datos = obtenerRegistrosNivel1($conn, $email);
    break;
  case 2:
    $datos = obtenerRegistrosNivel2($conn, $email);
    break;
  case 3:
    $datos = obtenerRegistrosNivel3($conn, $email);
    break;
  default:
    $datos = [];
}

// Formatear fecha antes de enviar el JSON
foreach ($datos as &$reg) {
  $reg['fecha'] = formatearFecha($reg['fecha']);
}
unset($reg);

header('Content-Type: application/json');
echo json_encode($datos);

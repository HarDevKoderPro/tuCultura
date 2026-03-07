<?php
// Configuración de conexión
$host = "190.8.176.115"; // Desarrollo Remoto
// $host = "localhost";
$user = "tucultur";
$password = "@GWMU!J4p-mgyTJ7";
$dbname = "tucultur_asociados";

// Crear conexión
$conn = new mysqli($host, $user, $password, $dbname);
mysqli_set_charset($conn, "utf8mb4");

// Verificar conexión
if ($conn->connect_error) {
  die("Error de conexión: " . $conn->connect_error);
}

// Función para contar registros generales del sistema
function contarRegistros($conn)
{
  $sql = "SELECT COUNT(*) AS total FROM registros";
  $result = $conn->query($sql);
  if ($result) {
    $row = $result->fetch_assoc();
    return $row['total'];
  }
  return 0;
}

// Función para contar registros NIVEL 1 (sin incluirse)
function contarRegistrosUsuario($conn, $emailUsuario)
{
  $sql = "SELECT COUNT(*) AS total 
          FROM registros 
          WHERE referente = '$emailUsuario'
            AND email <> '$emailUsuario'";
  $result = $conn->query($sql);
  if ($result) {
    $row = $result->fetch_assoc();
    return $row['total'];
  }
  return 0;
}

// Función para contar registros NIVEL 2
function contarRegistrosDeReferidos($conn, $emailUsuario)
{
  $sql = "SELECT COUNT(*) AS totalReferidos
            FROM registros
            WHERE referente IN (
                SELECT email
                FROM registros
                WHERE referente = '$emailUsuario'
                  AND email <> '$emailUsuario'
            )";
  $result = $conn->query($sql);
  if ($result) {
    $row = $result->fetch_assoc();
    return $row['totalReferidos'];
  }
  return 0;
}

// Función para contar registros de referidos en Nivel 3
function contarReferidosNivel3($conn, $emailUsuario)
{
  $total = 0;
  $sqlNivel1 = "SELECT email FROM registros WHERE referente = '$emailUsuario' AND email <> '$emailUsuario'";
  $resNivel1 = $conn->query($sqlNivel1);
  if ($resNivel1) {
    while ($row1 = $resNivel1->fetch_assoc()) {
      $emailNivel1 = $row1['email'];
      $sqlNivel2 = "SELECT email FROM registros WHERE referente = '$emailNivel1'";
      $resNivel2 = $conn->query($sqlNivel2);
      if ($resNivel2) {
        while ($row2 = $resNivel2->fetch_assoc()) {
          $emailNivel2 = $row2['email'];
          $sqlNivel3 = "SELECT COUNT(*) AS total FROM registros WHERE referente = '$emailNivel2'";
          $resNivel3 = $conn->query($sqlNivel3);
          if ($resNivel3) {
            $row3 = $resNivel3->fetch_assoc();
            $total += $row3['total'];
          }
        }
      }
    }
  }
  return $total;
}

// ─────────────────────────────────────────────
// FUNCIONES: Obtener registros por nivel
// ─────────────────────────────────────────────

// Obtener registros NIVEL 1
function obtenerRegistrosNivel1($conn, $emailUsuario)
{
  $stmt = $conn->prepare(
    "SELECT nombres, apellidos, documento, email, telefono, fecha 
     FROM registros 
     WHERE referente = ? AND email <> ? 
     ORDER BY fecha ASC"
  );
  $stmt->bind_param("ss", $emailUsuario, $emailUsuario);
  $stmt->execute();
  $result = $stmt->get_result();
  $datos = [];
  while ($row = $result->fetch_assoc()) {
    $datos[] = $row;
  }
  $stmt->close();
  return $datos;
}

// Obtener registros NIVEL 2
function obtenerRegistrosNivel2($conn, $emailUsuario)
{
  $stmt = $conn->prepare(
    "SELECT nombres, apellidos, documento, email, telefono, fecha 
     FROM registros 
     WHERE referente IN (
       SELECT email FROM registros 
       WHERE referente = ? AND email <> ?
     )
     ORDER BY fecha ASC"
  );
  $stmt->bind_param("ss", $emailUsuario, $emailUsuario);
  $stmt->execute();
  $result = $stmt->get_result();
  $datos = [];
  while ($row = $result->fetch_assoc()) {
    $datos[] = $row;
  }
  $stmt->close();
  return $datos;
}

// Obtener registros NIVEL 3
function obtenerRegistrosNivel3($conn, $emailUsuario)
{
  $datos = [];

  $stmt1 = $conn->prepare(
    "SELECT email FROM registros WHERE referente = ? AND email <> ?"
  );
  $stmt1->bind_param("ss", $emailUsuario, $emailUsuario);
  $stmt1->execute();
  $resNivel1 = $stmt1->get_result();
  $emailsNivel1 = [];
  while ($row = $resNivel1->fetch_assoc()) {
    $emailsNivel1[] = $row['email'];
  }
  $stmt1->close();

  if (empty($emailsNivel1)) return $datos;

  $placeholders1 = implode(',', array_fill(0, count($emailsNivel1), '?'));
  $types1 = str_repeat('s', count($emailsNivel1));
  $stmt2 = $conn->prepare(
    "SELECT email FROM registros WHERE referente IN ($placeholders1)"
  );
  $stmt2->bind_param($types1, ...$emailsNivel1);
  $stmt2->execute();
  $resNivel2 = $stmt2->get_result();
  $emailsNivel2 = [];
  while ($row = $resNivel2->fetch_assoc()) {
    $emailsNivel2[] = $row['email'];
  }
  $stmt2->close();

  if (empty($emailsNivel2)) return $datos;

  $placeholders2 = implode(',', array_fill(0, count($emailsNivel2), '?'));
  $types2 = str_repeat('s', count($emailsNivel2));
  $stmt3 = $conn->prepare(
    "SELECT nombres, apellidos, documento, email, telefono, fecha 
     FROM registros 
     WHERE referente IN ($placeholders2)
     ORDER BY fecha ASC"
  );
  $stmt3->bind_param($types2, ...$emailsNivel2);
  $stmt3->execute();
  $resNivel3 = $stmt3->get_result();
  while ($row = $resNivel3->fetch_assoc()) {
    $datos[] = $row;
  }
  $stmt3->close();

  return $datos;
}

// ─────────────────────────────────────────────
// HELPER: Formatear fecha a "04-Feb-2026"
// ─────────────────────────────────────────────
function formatearFecha($fecha)
{
  $meses = [
    '01' => 'Ene',
    '02' => 'Feb',
    '03' => 'Mar',
    '04' => 'Abr',
    '05' => 'May',
    '06' => 'Jun',
    '07' => 'Jul',
    '08' => 'Ago',
    '09' => 'Sep',
    '10' => 'Oct',
    '11' => 'Nov',
    '12' => 'Dic'
  ];
  if (empty($fecha)) return '-';
  $partes = explode('-', $fecha); // ['2026', '02', '04']
  if (count($partes) !== 3) return $fecha;
  [$anio, $mes, $dia] = $partes;
  $mesNombre = $meses[$mes] ?? $mes;
  return "{$dia}-{$mesNombre}-{$anio}";
}

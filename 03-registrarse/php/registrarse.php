<?php
// Configurar cabecera para respuesta JSON
header('Content-Type: application/json; charset=utf-8');

// Obtener los datos a enviar JSON desde JavaScript
$data = json_decode(file_get_contents("php://input"), true);

// Configurar credenciales de conexión a la base de datos
$host = "190.8.176.115"; // Desarrollo Remoto
// $host = "localhost"; // Desarrollo Local
$user = "tucultur";      // Usuario de MySQL
$password = "@GWMU!J4p-mgyTJ7";      // Contraseña de MySQL
$dbname = "tucultur_asociados"; // Nombre de la base de datos

// Conectar a base de datos MySQL
$conn = new mysqli($host, $user, $password, $dbname);

// Establecer la codificación de caracteres
mysqli_set_charset($conn, "utf8mb4");

// Verificar la conexión
if ($conn->connect_error) {
  die(json_encode(["respuesta" => "Error de conexión: " . $conn->connect_error]));
}

// Comprobar si los datos están presentes
if (isset(
  $data['nombres'],
  $data['apellidos'],
  $data['documento'],
  $data['telefono'],
  $data['email'],
  $data['pass'],
  $data['referente']
)) {

  // Pasar contenido de variables JS a variables PHP y eliminar espacios
  $nombres = trim($data['nombres']);
  $apellidos = trim($data['apellidos']);
  $documento = trim($data['documento']);
  $telefono = trim($data['telefono']);
  $email = trim($data['email']);
  $pass = trim($data['pass']);
  $referente = trim($data['referente']);

  // Extraer fecha enviada por el navegador o usar la del servidor como respaldo
  $fecha_actual = isset($data['fecha']) ? $data['fecha'] : date("Y-m-d");

  // --- NUEVA LÓGICA DE SEGURIDAD ---
  // 1. Hashear la contraseña antes de guardarla
  $pass_hashed = password_hash($pass, PASSWORD_DEFAULT);

  // 2. Usar Prepared Statement para la inserción
  $stmt = $conn->prepare("INSERT INTO registros (nombres, apellidos, documento, telefono, email, pass, referente, fecha) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

  if ($stmt) {
    // "ssssssss" indica que los 8 parámetros son strings
    $stmt->bind_param("ssssssss", $nombres, $apellidos, $documento, $telefono, $email, $pass_hashed, $referente, $fecha_actual);

    if ($stmt->execute()) {
      $respuesta = 'Datos enviados exitosamente!';
    } else {
      $respuesta = 'Error al almacenar los datos: ' . $stmt->error;
    }
    $stmt->close();
  } else {
    $respuesta = 'Error en la preparación de la consulta: ' . $conn->error;
  }

  // Respuesta del servidor compatible con el frontend
  echo json_encode(['respuesta' => $respuesta]);
} else {
  echo json_encode(['respuesta' => 'Datos faltantes']);
}

// Cerrar la conexión
$conn->close();

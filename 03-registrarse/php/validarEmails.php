<?php
// Obtener los datos a enviar JSON desde JavaScript
$data = json_decode(file_get_contents("php://input"), true);
$mensaje = '';

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
  die(json_encode(["success" => false, "message" => "Error de conexión: " . $conn->connect_error]));
}

// Compruebo si existen los datos
if (isset(
  $data['email'],
  $data['referente']
)) {

  // Paso contenido de variables JS a variables PHP
  // Elimino espacios al inicio y al final
  $email = trim($data['email']);
  $referente = trim($data['referente']);

  // Sanitizo las variable para evitar inyección de código
  $email = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $referente = htmlspecialchars($referente, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  // Consulto si el email ya existe en la tabla registros
  $sqlEmail = "SELECT 1 FROM registros WHERE email = '$email' LIMIT 1";
  $result = $conn->query($sqlEmail); // Resultado de la consulta

  // Consulto si el referente existe en la tabla registros
  $sqlReferente = "SELECT 1 FROM registros WHERE email = '$referente' LIMIT 1";
  $result2 = $conn->query($sqlReferente);  // Resultado de la consulta

  // Devolver ambos resultados en un solo JSON
  echo json_encode([
    'emailExists' => $result && $result->num_rows > 0,
    'referenteExists' => $result2 && $result2->num_rows > 0
  ]);

  // Si el email consultado no existe...
} else {
  echo json_encode(['status' => 'error', 'message' => 'Datos faltantes']);
}

// Cerrar la conexión
$conn->close();

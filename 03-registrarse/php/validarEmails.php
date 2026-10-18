<?php
// Obtener los datos a enviar JSON desde JavaScript
$data = json_decode(file_get_contents("php://input"), true);
$mensaje = '';

// Configurar credenciales de conexión a la base de datos
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'tucultur';
$password = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'tucultur_asociados';

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

  // Registro desde un enlace de invitacion: el referente llega como codigo
  // (el email del asociado nunca pasa por el navegador)
  if ($referente === '' && !empty($data['ref'])) {
    require_once __DIR__ . '/../../05-tienda/php/invitaciones-helpers.php';
    $referente = emailReferentePorCodigo($conn, $data['ref']);
  }

  // Consultas preparadas: los valores nunca se interpolan en el SQL.
  // Se comparan tal cual, igual que los guarda registrarse.php
  $stmt = $conn->prepare("SELECT 1 FROM registros WHERE email = ? LIMIT 1");

  // Consulto si el email ya existe en la tabla registros
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $stmt->store_result();
  $emailExists = $stmt->num_rows > 0;

  // Consulto si el referente existe en la tabla registros
  $stmt->bind_param("s", $referente);
  $stmt->execute();
  $stmt->store_result();
  $referenteExists = $stmt->num_rows > 0;

  $stmt->close();

  // Devolver ambos resultados en un solo JSON
  echo json_encode([
    'emailExists' => $emailExists,
    'referenteExists' => $referenteExists
  ]);

  // Si el email consultado no existe...
} else {
  echo json_encode(['status' => 'error', 'message' => 'Datos faltantes']);
}

// Cerrar la conexión
$conn->close();

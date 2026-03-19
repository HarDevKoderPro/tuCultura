<?php
session_start();
header('Content-Type: application/json');

// Leer JSON enviado por el JS
$data = json_decode(file_get_contents("php://input"), true);

// Configurar credenciales de conexión a la base de datos
$host = "190.8.176.115"; // Desarrollo Remoto
// $host = "localhost"; // Desarrollo Local
$user = "tucultur";      // Usuario de MySQL
$password = "@GWMU!J4p-mgyTJ7";      // Contraseña de MySQL
$dbname = "tucultur_asociados"; // Nombre de la base de datos

$conn = new mysqli($host, $user, $password, $dbname);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
  echo json_encode(["respuesta" => false, "mensaje" => "Error de servidor."]);
  exit;
}

// Verificar que llegaron los datos
if (!isset($data['email'], $data['pass'])) {
  echo json_encode(["respuesta" => false, "mensaje" => "Datos incompletos."]);
  exit;
}

$email = trim($data['email']);
$pass  = trim($data['pass']);

// ✅ Leer parámetros de redirección enviados desde el frontend
// Estos parámetros permiten que PHP decida la URL de destino tras el login
$origen    = isset($data['origen']) ? trim($data['origen']) : '';
$returnUrl = isset($data['returnUrl']) ? trim($data['returnUrl']) : '';

// ✅ Consulta con nombres Y apellidos
$stmt = $conn->prepare("SELECT id, pass, nombres, apellidos FROM registros WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

$login_valido = false;
$esAdmin      = false;

if ($result->num_rows === 1) {
  $usuario = $result->fetch_assoc();

  if (password_verify($pass, $usuario['pass'])) {
    $login_valido = true;

    // ✅ Guardar TODOS los datos en sesión
    $_SESSION['user_id']   = $usuario['id'];
    $_SESSION['email']     = $email;
    $_SESSION['nombres']   = $usuario['nombres'];
    $_SESSION['apellidos'] = $usuario['apellidos'];

    // Lista de administradores
    $admins  = ['luisfer5428@gmail.com', 'haroldvaldes@yahoo.com'];
    $esAdmin = in_array(strtolower($email), array_map('strtolower', $admins));

    // ✅ Guardar rol en sesión para diferenciar admin de cliente
    $_SESSION['rol'] = $esAdmin ? 'admin' : 'cliente';
  }
}

if ($login_valido) {
  // =====================================================
  // ✅ LÓGICA DE REDIRECCIÓN CENTRALIZADA EN PHP
  // PHP decide la URL de destino. JS solo redirige a donde PHP indique.
  // Esto elimina la dependencia de sessionStorage para redirecciones.
  // =====================================================

  $redirectUrl = '';

  if ($origen === 'homepage') {
    // ✅ 1. Viene de homepage → SIEMPRE ir a contenido (incluido admin)
    // Admin que entra desde "Usuarios" en homepage debe ver contenido, no panel admin
    $redirectUrl = '../04-contenido/php/contenido.php';
  } elseif ($esAdmin && $origen === 'tienda') {
    // ✅ 2. Admin que viene de tienda → panel admin
    $redirectUrl = '../05-tienda/admin/index.html';
  } elseif (!empty($returnUrl)) {
    // ✅ 3. Cliente con returnUrl (ej: viene del checkout)
    // Solo permitir URLs relativas dentro de la tienda por seguridad
    $allowedReturns = ['checkout.html', 'carrito.html', 'mis-pedidos.html'];
    if (in_array($returnUrl, $allowedReturns)) {
      $redirectUrl = '../05-tienda/' . $returnUrl;
    } else {
      // returnUrl no reconocida → comportamiento por defecto
      $redirectUrl = '../04-contenido/php/contenido.php';
    }
  } elseif ($origen === 'tienda') {
    // ✅ 4. Cliente que viene de la tienda pero sin returnUrl específico
    $redirectUrl = '../05-tienda/index.html';
  } else {
    // ✅ 5. Default → ir a contenido
    $redirectUrl = '../04-contenido/php/contenido.php';
  }

  echo json_encode([
    "respuesta"   => true,
    "esAdmin"     => $esAdmin,
    "redirectUrl" => $redirectUrl
  ]);
} else {
  // Mensaje genérico por seguridad (no especifica si es correo o contraseña)
  echo json_encode([
    "respuesta" => false,
    "mensaje"   => "Correo o contraseña incorrectos."
  ]);
}

$stmt->close();
$conn->close();

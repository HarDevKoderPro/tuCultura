<?php
header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$data = json_decode(file_get_contents("php://input"), true);

// Configurar credenciales de conexión a la base de datos
$host = "190.8.176.115"; // Desarrollo Remoto
// $host = "localhost"; // Desarrollo Local
$user = "tucultur";      // Usuario de MySQL
$password = "@GWMU!J4p-mgyTJ7";      // Contraseña de MySQL
$dbname = "tucultur_asociados"; // Nombre de la base de datos

$conn = new mysqli($host, $user, $password, $dbname);
mysqli_set_charset($conn, "utf8mb4");

if ($conn->connect_error) {
  die(json_encode(["respuesta" => false, "message" => "Error de conexión"]));
}

// ACCIÓN 1: SOLICITAR CÓDIGO (Ya validado)
if (isset($data['email']) && !isset($data['codigo']) && !isset($data['nuevaPass'])) {
  $email = trim($data['email']);
  $stmt = $conn->prepare("SELECT id FROM registros WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows > 0) {
    $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiracion = date("Y-m-d H:i:s", strtotime('+5 minutes'));

    $update = $conn->prepare("UPDATE registros SET recovery_code = ?, recovery_expires = ? WHERE email = ?");
    $update->bind_param("sss", $codigo, $expiracion, $email);

    if ($update->execute()) {
      $mail = new PHPMailer(true);
      try {
        $mail->isSMTP();
        $mail->Host       = 'mail.tuculturaesprogreso.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contacto@tuculturaesprogreso.com';
        $mail->Password   = '@GWMU!J4p-mgyTJ7';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('contacto@tuculturaesprogreso.com', 'TuCultur Soporte');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Codigo de Recuperacion - TuCultur';
        $mail->Body    = "Tu codigo es: <b>$codigo</b>. Expira en 5 minutos.";
        $mail->send();
        echo json_encode(["respuesta" => true, "message" => "Código enviado"]);
      } catch (Exception $e) {
        echo json_encode(["respuesta" => false, "message" => "Error SMTP: {$mail->ErrorInfo}"]);
      }
    }
    $update->close();
  } else {
    echo json_encode(["respuesta" => false, "message" => "El correo no está registrado"]);
  }
  $stmt->close();
}

// ACCIÓN 2: VERIFICAR CÓDIGO (Nuevo)
else if (isset($data['email'], $data['codigo']) && !isset($data['nuevaPass'])) {
  $email = trim($data['email']);
  $codigoIngresado = trim($data['codigo']);
  $ahora = date("Y-m-d H:i:s");

  $stmt = $conn->prepare("SELECT recovery_code, recovery_expires FROM registros WHERE email = ? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($usuario = $result->fetch_assoc()) {
    if ($usuario['recovery_code'] === $codigoIngresado) {
      if ($ahora <= $usuario['recovery_expires']) {
        echo json_encode(["respuesta" => true, "message" => "Código válido"]);
      } else {
        echo json_encode(["respuesta" => false, "message" => "El código ha expirado"]);
      }
    } else {
      echo json_encode(["respuesta" => false, "message" => "Código incorrecto"]);
    }
  }
  $stmt->close();
}

// ACCIÓN 3: ACTUALIZAR CONTRASEÑA (Nuevo)
else if (isset($data['email'], $data['nuevaPass'])) {
  $email = trim($data['email']);
  $nuevaPassHash = password_hash($data['nuevaPass'], PASSWORD_DEFAULT);

  $stmt = $conn->prepare("UPDATE registros SET pass = ?, recovery_code = NULL, recovery_expires = NULL WHERE email = ?");
  $stmt->bind_param("ss", $nuevaPassHash, $email);

  if ($stmt->execute()) {
    echo json_encode(["respuesta" => true, "message" => "Contraseña actualizada"]);
  } else {
    echo json_encode(["respuesta" => false, "message" => "Error al actualizar"]);
  }
  $stmt->close();
}

$conn->close();

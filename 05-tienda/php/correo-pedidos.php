<?php

/**
 * Correos de pedidos de la tienda (PHPMailer + SMTP de contacto@).
 *
 * Se envia al comprador y una copia de aviso a la tienda cuando:
 * - se crea un pedido contraentrega/transferencia (pedidos.php?action=crear)
 * - MercadoPago aprueba el pago de un pedido (pagos.php)
 *
 * Nunca interrumpe el flujo de compra: cualquier error se registra en el log.
 */

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../../02-iniciarSesion/php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../02-iniciarSesion/php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../02-iniciarSesion/php/PHPMailer/src/SMTP.php';

if (!function_exists('enviarCorreosPedido')) {
  function enviarCorreosPedido($conn, $pedidoId)
  {
    try {
      $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
      $stmt->bind_param('i', $pedidoId);
      $stmt->execute();
      $pedido = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$pedido) {
        return false;
      }

      $stmt = $conn->prepare(
        "SELECT nombre_producto, cantidad, precio_unitario, subtotal
         FROM detalle_pedido WHERE pedido_id = ?"
      );
      $stmt->bind_param('i', $pedidoId);
      $stmt->execute();
      $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();

      $numero = $pedido['numero_pedido'];
      $cuerpo = construirCorreoPedido($pedido, $detalles);

      $esMercadoPago = $pedido['metodo_pago'] === 'mercadopago';
      $asuntoCliente = $esMercadoPago
        ? "Pago aprobado - Pedido $numero"
        : "Recibimos tu pedido $numero";

      // Modo pruebas: con TIENDA_EMAIL_PRUEBAS definido, el correo del comprador se
      // redirige ahi (los clientes de prueba tienen emails de personas reales)
      $destinoCliente = $pedido['email_envio'];
      $emailPruebas = getenv('TIENDA_EMAIL_PRUEBAS');
      if ($emailPruebas) {
        $asuntoCliente = '[PRUEBA para ' . html_entity_decode($destinoCliente, ENT_QUOTES, 'UTF-8') . '] ' . $asuntoCliente;
        $destinoCliente = $emailPruebas;
      }

      $okCliente = enviarCorreoTienda($destinoCliente, $asuntoCliente, $cuerpo);

      $avisoTienda = getenv('TIENDA_EMAIL_NOTIFICACIONES') ?: (getenv('SMTP_USER') ?: 'contacto@tuculturaesprogreso.com');
      enviarCorreoTienda(
        $avisoTienda,
        "Nuevo pedido $numero (" . $pedido['metodo_pago'] . ") - " . formatearPrecio($pedido['total']),
        $cuerpo
      );

      return $okCliente;
    } catch (Throwable $e) {
      error_log('correo-pedidos.php: ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('enviarCorreoTienda')) {
  function enviarCorreoTienda($destino, $asunto, $html)
  {
    if (!filter_var(html_entity_decode((string)$destino, ENT_QUOTES, 'UTF-8'), FILTER_VALIDATE_EMAIL)) {
      return false;
    }

    $remitente = getenv('SMTP_USER') ?: 'contacto@tuculturaesprogreso.com';

    $mail = new PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host       = getenv('SMTP_HOST') ?: 'mail.tuculturaesprogreso.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = $remitente;
      $mail->Password   = getenv('SMTP_PASSWORD') ?: '';
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
      $mail->Port       = 465;
      $mail->Timeout    = 10;
      $mail->CharSet    = 'UTF-8';
      $mail->setFrom($remitente, 'Tu Cultura es Progreso - Tienda');
      $mail->addAddress(html_entity_decode((string)$destino, ENT_QUOTES, 'UTF-8'));
      $mail->isHTML(true);
      $mail->Subject = $asunto;
      $mail->Body    = $html;
      $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</tr>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
      $mail->send();
      return true;
    } catch (Throwable $e) {
      error_log('correo-pedidos.php SMTP: ' . $mail->ErrorInfo);
      return false;
    }
  }
}

if (!function_exists('construirCorreoPedido')) {
  function construirCorreoPedido($pedido, $detalles)
  {
    // Los datos de envio ya vienen escapados por sanitizar(); no se re-escapan
    $e = function ($valor) {
      return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8', false);
    };

    switch ($pedido['metodo_pago']) {
      case 'mercadopago':
        $mensajePago = 'Tu pago con MercadoPago fue <b>aprobado</b> y tu pedido está confirmado.';
        break;
      case 'transferencia':
        $mensajePago = 'Elegiste <b>transferencia bancaria</b>. Te contactaremos con los datos para realizar el pago.';
        break;
      default:
        $mensajePago = 'Elegiste <b>pago contra entrega</b>: pagas cuando recibas tu pedido.';
    }

    $filas = '';
    foreach ($detalles as $d) {
      $filas .= '<tr>'
        . '<td style="padding:6px;border-bottom:1px solid #eee">' . $e($d['nombre_producto']) . '</td>'
        . '<td style="padding:6px;border-bottom:1px solid #eee;text-align:center">' . (int)$d['cantidad'] . '</td>'
        . '<td style="padding:6px;border-bottom:1px solid #eee;text-align:right">' . formatearPrecio($d['subtotal']) . '</td>'
        . '</tr>';
    }

    $envio = (float)$pedido['costo_envio'] > 0 ? formatearPrecio($pedido['costo_envio']) : 'GRATIS';

    return '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#333">'
      . '<h2 style="color:#023859">Pedido ' . $e($pedido['numero_pedido']) . '</h2>'
      . '<p>Hola ' . $e($pedido['nombre_envio']) . ',</p>'
      . '<p>' . $mensajePago . '</p>'
      . '<table style="width:100%;border-collapse:collapse;margin:16px 0">'
      . '<tr style="background:#023859;color:#fff"><th style="padding:6px;text-align:left">Producto</th><th style="padding:6px">Cant.</th><th style="padding:6px;text-align:right">Subtotal</th></tr>'
      . $filas
      . '<tr><td colspan="2" style="padding:6px;text-align:right">Envío</td><td style="padding:6px;text-align:right">' . $envio . '</td></tr>'
      . '<tr><td colspan="2" style="padding:6px;text-align:right"><b>Total</b></td><td style="padding:6px;text-align:right"><b>' . formatearPrecio($pedido['total']) . '</b></td></tr>'
      . '</table>'
      . '<p><b>Envío a:</b><br>'
      . $e($pedido['nombre_envio']) . ' ' . $e($pedido['apellido_envio']) . '<br>'
      . $e($pedido['direccion_envio']) . '<br>'
      . $e($pedido['ciudad_envio']) . ', ' . $e($pedido['departamento_envio']) . '<br>'
      . 'Tel: ' . $e($pedido['telefono_envio']) . '</p>'
      . '<p>Puedes revisar el estado de tu pedido en "Mis Pedidos" de la tienda.</p>'
      . '<p style="color:#888;font-size:12px">Tu Cultura es Progreso</p>'
      . '</div>';
  }
}

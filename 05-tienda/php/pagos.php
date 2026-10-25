<?php
/**
 * API de Pagos (MercadoPago Checkout Pro) + puntos por referidos
 *
 * - crear_preferencia: crea el pedido en estado "pendiente" (sin descontar stock
 *   ni vaciar carrito) y la preferencia de pago en MercadoPago.
 * - webhook_mercadopago: MercadoPago notifica; se valida la firma (si hay secreto),
 *   se consulta el pago en la API y, si esta aprobado, se confirma el pedido,
 *   se descuenta stock, se quitan los productos del carrito y se asignan puntos
 *   (comprador + referente nivel 1 + nivel 2). Todo en una transaccion e idempotente.
 * - estado_pedido: la pagina de confirmacion consulta el resultado al volver de MercadoPago.
 * - cobrar: enlace de cobro (pagina HTML) de un pedido contra entrega; el cliente o el
 *   transportista pagan por MercadoPago y el webhook lo registra (sin tocar stock).
 */

require_once 'config.php';
require_once 'puntos-pagos-helpers.php';
require_once 'correo-pedidos.php';
require_once 'bonos-helpers.php';
require_once 'cobros-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// PUNTOS_POR_COMPRA y PUNTOS_NIVELES_REFERIDOS: ver puntos-pagos-helpers.php

// La preferencia (y los pagos en efectivo) vencen a las N horas; despues
// cancelar_pedidos_abandonados.php cancela los pedidos que nunca se pagaron
define('MP_HORAS_EXPIRACION', 48);

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : '';

switch ($action) {
  case 'crear_preferencia':
    crearPreferenciaSandbox($conn);
    break;
  case 'webhook_mercadopago':
    webhookMercadoPago($conn);
    break;
  case 'estado_pedido':
    estadoPedidoPago($conn);
    break;
  case 'cobrar':
    paginaCobroPedido($conn);
    break;
  default:
    jsonResponse(['success' => false, 'error' => 'Accion no valida'], 400);
}

function crearPreferenciaSandbox($conn)
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Metodo no permitido'], 405);
  }

  if (!usuarioLogueado()) {
    jsonResponse([
      'success' => false,
      'error' => 'Debe iniciar sesion para continuar',
      'requiere_login' => true
    ], 401);
  }

  if (!esClienteLogueado()) {
    jsonResponse([
      'success' => false,
      'error' => 'Los administradores no pueden realizar compras.',
      'es_admin' => true
    ], 403);
  }

  $accessToken = obtenerAccessTokenMercadoPago();
  if ($accessToken === '') {
    jsonResponse([
      'success' => false,
      'error' => 'Falta configurar credencial de MercadoPago',
      'requiere_configuracion' => true,
      'config_keys_esperadas' => ['MERCADOPAGO_ACCESS_TOKEN', 'MP_ACCESS_TOKEN']
    ], 500);
  }

  $usuarioId = obtenerUsuarioId($conn, $_SESSION['email']);
  if (!$usuarioId) {
    jsonResponse(['success' => false, 'error' => 'Usuario no encontrado'], 404);
  }

  $usuario = obtenerDatosUsuario($conn, $_SESSION['email']);
  if (!$usuario) {
    jsonResponse(['success' => false, 'error' => 'No se pudieron cargar datos del usuario'], 404);
  }

  $input = obtenerEntradaCheckout();
  $usarBono = !empty($_POST['usar_bono']);

  // Mismos campos obligatorios que pedidos.php?action=crear
  foreach (['nombre', 'apellido', 'telefono', 'email', 'direccion', 'ciudad', 'departamento'] as $campo) {
    if ($input[$campo] === '') {
      jsonResponse(['success' => false, 'error' => "El campo '$campo' es requerido"], 400);
    }
  }
  if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Email no valido'], 400);
  }

  $carrito = obtenerCarritoParaPago($conn, (int)$usuarioId);
  if (empty($carrito['items'])) {
    jsonResponse([
      'success' => false,
      'error' => 'El carrito esta vacio',
      'carrito_vacio' => true
    ], 400);
  }

  // Si el bono cubre toda la compra no hay nada que pagar en MercadoPago.
  // Tablas de bonos listas ANTES de la transaccion (un ALTER TABLE haria commit implicito)
  if ($usarBono) {
    asegurarTablaBonos($conn);
    $valorBono = valorBonoCop($conn);
    if ($valorBono > 0 && $valorBono >= (float)$carrito['total']) {
      jsonResponse([
        'success' => false,
        'error' => 'Tu bono cubre toda la compra: elige Pago Contra Entrega (no tendrás que pagar nada).',
        'bono_cubre_total' => true
      ], 400);
    }
  }

  $numeroPedido = generarNumeroPedido();
  // El numero de pedido viaja como external_reference: une el pago con el pedido
  $externalReference = $numeroPedido;
  $idempotencyKey = construirIdempotencyKey($usuarioId, $carrito['total']);
  $urls = construirUrlsRetorno($numeroPedido);
  $vencimiento = date('Y-m-d\TH:i:s.vP', time() + MP_HORAS_EXPIRACION * 3600);

  $payloadPreferencia = [
    'items' => array_values($carrito['items_mp']),
    'payer' => [
      'name' => (string)$input['nombre'],
      'surname' => (string)$input['apellido'],
      'email' => (string)$input['email'],
      'phone' => [
        'number' => (string)$input['telefono']
      ]
    ],
    'external_reference' => $externalReference,
    'statement_descriptor' => 'TU CULTURA',
    'back_urls' => [
      'success' => $urls['success'],
      'pending' => $urls['pending'],
      'failure' => $urls['failure']
    ],
    'auto_return' => 'approved',
    'notification_url' => $urls['webhook'],
    'expires' => true,
    'expiration_date_to' => $vencimiento,
    'date_of_expiration' => $vencimiento,
    'metadata' => [
      'modulo' => '05-tienda',
      'usuario_id' => (int)$usuarioId,
      'usuario_email' => (string)$usuario['email'],
      'numero_pedido' => $numeroPedido,
      'origen' => 'checkout_tienda'
    ]
  ];

  $payloadRegistro = [
    'entrada_checkout' => $input,
    'carrito' => [
      'cantidad_items' => count($carrito['items']),
      'subtotal' => $carrito['subtotal'],
      'costo_envio' => $carrito['costo_envio'],
      'impuestos' => $carrito['impuestos'],
      'total' => $carrito['total']
    ],
    'preference_payload' => $payloadPreferencia
  ];

  // Pedido pendiente + detalle + intento de pago, todo o nada
  $conn->begin_transaction();
  try {
    $pedidoId = crearPedidoPendienteMP($conn, (int)$usuarioId, $numeroPedido, $carrito, $input);

    // Bono redimido: queda reservado en este pedido (si el pedido se cancela, vuelve).
    // MercadoPago cobra un solo item por el total con el descuento.
    if ($usarBono) {
      $aplicado = aplicarBonoAPedido($conn, (int)$usuarioId, (int)$pedidoId, (float)$carrito['total'], (string)$usuario['email']);
      $carrito['descuento_bono'] = $aplicado['descuento'];
      $carrito['total'] = round(max(0, (float)$carrito['total'] - $aplicado['descuento']), 2);
      $payloadPreferencia['items'] = [[
        'id' => 'pedido-' . $numeroPedido,
        'title' => 'Compra Tu Cultura (pedido ' . $numeroPedido . ', con bono)',
        'description' => 'Productos de la tienda menos el bono por puntos',
        'quantity' => 1,
        'currency_id' => TIENDA_MONEDA,
        'unit_price' => (float)$carrito['total']
      ]];
      $payloadRegistro['carrito']['descuento_bono'] = $aplicado['descuento'];
      $payloadRegistro['carrito']['total'] = $carrito['total'];
      $payloadRegistro['preference_payload'] = $payloadPreferencia;
    }

    $pagoLocalId = insertarIntentoPago(
      $conn,
      $pedidoId,
      (int)$usuarioId,
      'mercadopago',
      'pendiente_preferencia',
      null,
      $externalReference,
      $idempotencyKey,
      (float)$carrito['total'],
      TIENDA_MONEDA,
      'Creacion de preferencia',
      $payloadRegistro
    );
    if (!$pagoLocalId) {
      throw new Exception('No fue posible registrar el intento de pago');
    }

    $conn->commit();
  } catch (BonoException $e) {
    // Bono no disponible o sin valor definido
    $conn->rollback();
    jsonResponse(['success' => false, 'error' => $e->getMessage(), 'bono_no_disponible' => true], 400);
  } catch (Exception $e) {
    $conn->rollback();
    error_log('pagos.php crear_preferencia: ' . $e->getMessage());
    jsonResponse([
      'success' => false,
      'error' => 'No fue posible registrar el pedido. Intente nuevamente.'
    ], 500);
  }

  $respuestaMP = crearPreferenciaMercadoPago($accessToken, $payloadPreferencia, $idempotencyKey);

  if (!$respuestaMP['success']) {
    actualizarIntentoPago(
      $conn,
      (int)$pagoLocalId,
      'error_preferencia',
      null,
      substr((string)$respuestaMP['error'], 0, 120),
      ['mercadopago_error' => $respuestaMP]
    );
    // Sin preferencia no hay forma de pagar: el pedido no debe quedar pendiente
    actualizarEstadoPedido($conn, $pedidoId, 'cancelado');
    if ($usarBono) {
      liberarBonosDePedidosCancelados($conn);
    }

    jsonResponse([
      'success' => false,
      'error' => 'No se pudo crear la preferencia de pago',
      'detalle' => $respuestaMP['error'],
      'codigo_http' => $respuestaMP['status']
    ], 502);
  }

  $pref = $respuestaMP['data'];

  actualizarIntentoPago(
    $conn,
    (int)$pagoLocalId,
    'preferencia_creada',
    (string)$pref['id'],
    'Preferencia creada en MercadoPago',
    ['mercadopago_response' => $pref]
  );

  jsonResponse([
    'success' => true,
    'sandbox' => esTokenSandbox($accessToken),
    'mensaje' => 'Preferencia creada correctamente',
    'pago' => [
      'id_local' => (int)$pagoLocalId,
      'external_reference' => $externalReference,
      'idempotency_key' => $idempotencyKey,
      'estado' => 'preferencia_creada'
    ],
    'pedido' => [
      'id' => (int)$pedidoId,
      'numero' => $numeroPedido,
      'estado' => 'pendiente'
    ],
    'preferencia' => [
      'id' => $pref['id'] ?? null,
      'init_point' => $pref['init_point'] ?? null,
      'sandbox_init_point' => $pref['sandbox_init_point'] ?? null
    ],
    'resumen' => [
      'cantidad_items' => count($carrito['items']),
      'subtotal' => $carrito['subtotal'],
      'costo_envio' => $carrito['costo_envio'],
      'impuestos' => $carrito['impuestos'],
      'descuento_bono' => $carrito['descuento_bono'] ?? 0,
      'total' => $carrito['total'],
      'total_formateado' => formatearPrecio($carrito['total'])
    ]
  ]);
}

function obtenerCarritoParaPago($conn, $usuarioId)
{
  $stmt = $conn->prepare(
    "SELECT c.producto_id, c.cantidad, p.nombre, p.descripcion_corta, p.precio, p.precio_oferta, p.imagen, p.stock
     FROM carrito c
     JOIN productos p ON c.producto_id = p.id
     WHERE c.usuario_id = ? AND p.activo = 1"
  );
  $stmt->bind_param('i', $usuarioId);
  $stmt->execute();
  $result = $stmt->get_result();

  $items = [];
  $itemsMP = [];
  $subtotal = 0.0;

  while ($row = $result->fetch_assoc()) {
    $cantidad = max(1, (int)$row['cantidad']);
    $stock = (int)$row['stock'];

    if ($cantidad > $stock) {
      $stmt->close();
      jsonResponse([
        'success' => false,
        'error' => "Stock insuficiente para '{$row['nombre']}'",
        'producto_sin_stock' => $row['nombre']
      ], 400);
    }

    $precioUnitario = isset($row['precio_oferta']) && $row['precio_oferta'] !== null
      ? (float)$row['precio_oferta']
      : (float)$row['precio'];

    $subtotalItem = $precioUnitario * $cantidad;
    $subtotal += $subtotalItem;

    $items[] = [
      'producto_id' => (int)$row['producto_id'],
      'nombre' => (string)$row['nombre'],
      'cantidad' => $cantidad,
      'precio_unitario' => $precioUnitario,
      'subtotal_item' => $subtotalItem
    ];

    $itemsMP[] = [
      'id' => (string)$row['producto_id'],
      'title' => (string)$row['nombre'],
      'description' => (string)($row['descripcion_corta'] ?? ''),
      'quantity' => $cantidad,
      'currency_id' => TIENDA_MONEDA,
      'unit_price' => round($precioUnitario, 2)
    ];
  }

  $stmt->close();

  $costoEnvio = $subtotal >= TIENDA_ENVIO_GRATIS_MINIMO ? 0.0 : (float)TIENDA_COSTO_ENVIO_BASE;
  $impuestos = $subtotal * (float)TIENDA_IVA;
  $total = $subtotal + $costoEnvio + $impuestos;

  if ($costoEnvio > 0) {
    $itemsMP[] = [
      'id' => 'shipping',
      'title' => 'Costo de envio',
      'description' => 'Envio tienda Tu Cultura',
      'quantity' => 1,
      'currency_id' => TIENDA_MONEDA,
      'unit_price' => round($costoEnvio, 2)
    ];
  }

  if ($impuestos > 0) {
    $itemsMP[] = [
      'id' => 'taxes',
      'title' => 'Impuestos',
      'description' => 'Impuestos de la compra',
      'quantity' => 1,
      'currency_id' => TIENDA_MONEDA,
      'unit_price' => round($impuestos, 2)
    ];
  }

  return [
    'items' => $items,
    'items_mp' => $itemsMP,
    'subtotal' => round($subtotal, 2),
    'costo_envio' => round($costoEnvio, 2),
    'impuestos' => round($impuestos, 2),
    'total' => round($total, 2)
  ];
}

function obtenerEntradaCheckout()
{
  $input = [];
  $raw = file_get_contents('php://input');

  if (!empty($raw) && (empty($_POST) || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false)) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $input = $json;
    }
  }

  if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
  }

  return [
    'nombre' => sanitizar((string)($input['nombre'] ?? '')),
    'apellido' => sanitizar((string)($input['apellido'] ?? '')),
    'email' => sanitizar((string)($input['email'] ?? '')),
    'telefono' => sanitizar((string)($input['telefono'] ?? '')),
    'direccion' => sanitizar((string)($input['direccion'] ?? '')),
    'ciudad' => sanitizar((string)($input['ciudad'] ?? '')),
    'departamento' => sanitizar((string)($input['departamento'] ?? '')),
    'codigo_postal' => sanitizar((string)($input['codigo_postal'] ?? '')),
    'notas' => sanitizar((string)($input['notas'] ?? ''))
  ];
}

function obtenerAccessTokenMercadoPago()
{
  if (defined('MERCADOPAGO_ACCESS_TOKEN') && MERCADOPAGO_ACCESS_TOKEN) {
    return trim((string)MERCADOPAGO_ACCESS_TOKEN);
  }

  if (defined('MP_ACCESS_TOKEN') && MP_ACCESS_TOKEN) {
    return trim((string)MP_ACCESS_TOKEN);
  }

  $keys = ['MERCADOPAGO_ACCESS_TOKEN', 'MP_ACCESS_TOKEN'];
  foreach ($keys as $key) {
    $value = getenv($key);
    if ($value !== false && trim((string)$value) !== '') {
      return trim((string)$value);
    }

    if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
      return trim((string)$_ENV[$key]);
    }

    if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
      return trim((string)$_SERVER[$key]);
    }
  }

  return '';
}

function esTokenSandbox($token)
{
  return stripos((string)$token, 'TEST-') === 0;
}

function construirExternalReference($usuarioId)
{
  return 'TCP-MP-U' . (int)$usuarioId . '-' . date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 8);
}

function construirIdempotencyKey($usuarioId, $total)
{
  $random = '';
  if (function_exists('random_bytes')) {
    try {
      $random = bin2hex(random_bytes(6));
    } catch (Exception $e) {
      $random = substr(sha1(uniqid('', true)), 0, 12);
    }
  } else {
    $random = substr(sha1(uniqid('', true)), 0, 12);
  }

  return hash('sha256', implode('|', [
    'tcp',
    'mp_pref',
    (int)$usuarioId,
    number_format((float)$total, 2, '.', ''),
    microtime(true),
    $random
  ]));
}

function construirUrlsRetorno($numeroPedido)
{
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

  $scheme = $isHttps ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $basePublic = rtrim($scheme . '://' . $host . BASE_URL, '/');

  // Los tres casos vuelven a confirmacion.html, que consulta el estado real del pago
  $retorno = $basePublic . '/confirmacion.html?pedido=' . rawurlencode($numeroPedido) . '&mp=1';

  return [
    'success' => $retorno,
    'pending' => $retorno,
    'failure' => $retorno,
    'webhook' => $basePublic . '/php/pagos.php?action=webhook_mercadopago'
  ];
}

function crearPreferenciaMercadoPago($accessToken, $payload, $idempotencyKey)
{
  if (!function_exists('curl_init')) {
    return [
      'success' => false,
      'status' => 500,
      'error' => 'cURL no disponible en el servidor'
    ];
  }

  $url = 'https://api.mercadopago.com/checkout/preferences';
  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json',
    'X-Idempotency-Key: ' . $idempotencyKey
  ]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);

  $response = curl_exec($ch);
  $curlError = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($curlError) {
    return [
      'success' => false,
      'status' => 500,
      'error' => 'Error de conexion con MercadoPago: ' . $curlError
    ];
  }

  $data = json_decode((string)$response, true);

  if (!is_array($data)) {
    return [
      'success' => false,
      'status' => $httpCode > 0 ? $httpCode : 502,
      'error' => 'Respuesta invalida de MercadoPago'
    ];
  }

  if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
    return [
      'success' => true,
      'status' => $httpCode,
      'data' => $data
    ];
  }

  $msg = $data['message'] ?? ($data['error'] ?? 'Error desconocido');
  if (!empty($data['cause']) && is_array($data['cause'])) {
    $msg .= ' | cause: ' . json_encode($data['cause'], JSON_UNESCAPED_UNICODE);
  }

  return [
    'success' => false,
    'status' => $httpCode > 0 ? $httpCode : 502,
    'error' => $msg,
    'raw' => $data
  ];
}

function insertarIntentoPago(
  $conn,
  $pedidoId,
  $usuarioId,
  $metodoPago,
  $estado,
  $preferenceId,
  $externalReference,
  $idempotencyKey,
  $monto,
  $moneda,
  $detalleEstado,
  $payload
) {
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare(
    "INSERT INTO pagos_tienda
      (pedido_id, usuario_id, metodo_pago, estado, preference_id, external_reference, idempotency_key, monto, moneda, detalle_estado, payload_json)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  $stmt->bind_param(
    'iisssssdsss',
    $pedidoId,
    $usuarioId,
    $metodoPago,
    $estado,
    $preferenceId,
    $externalReference,
    $idempotencyKey,
    $monto,
    $moneda,
    $detalleEstado,
    $payloadJson
  );

  $ok = $stmt->execute();
  $id = $ok ? (int)$stmt->insert_id : 0;
  $stmt->close();

  return $id;
}

function actualizarIntentoPago($conn, $pagoId, $estado, $preferenceId, $detalleEstado, $payload)
{
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare(
    "UPDATE pagos_tienda
     SET estado = ?, preference_id = ?, detalle_estado = ?, payload_json = ?
     WHERE id = ?"
  );

  $stmt->bind_param('ssssi', $estado, $preferenceId, $detalleEstado, $payloadJson, $pagoId);
  $ok = $stmt->execute();
  $stmt->close();

  return $ok;
}


/**
 * Inserta el pedido en estado "pendiente" con su detalle.
 * No descuenta stock ni vacia el carrito: eso ocurre cuando el pago se aprueba.
 * Debe llamarse dentro de una transaccion.
 */
function crearPedidoPendienteMP($conn, $usuarioId, $numeroPedido, $carrito, $input)
{
  $metodoPago = 'mercadopago';
  $subtotal = (float)$carrito['subtotal'];
  $costoEnvio = (float)$carrito['costo_envio'];
  $impuestos = (float)$carrito['impuestos'];
  $total = (float)$carrito['total'];

  $stmt = $conn->prepare(
    "INSERT INTO pedidos (
       numero_pedido, usuario_id, subtotal, costo_envio, impuestos, total, estado,
       metodo_pago, nombre_envio, apellido_envio, telefono_envio, email_envio,
       direccion_envio, ciudad_envio, departamento_envio, codigo_postal, notas
     ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param(
    'siddddssssssssss',
    $numeroPedido, $usuarioId, $subtotal, $costoEnvio, $impuestos, $total,
    $metodoPago, $input['nombre'], $input['apellido'], $input['telefono'], $input['email'],
    $input['direccion'], $input['ciudad'], $input['departamento'], $input['codigo_postal'], $input['notas']
  );
  $stmt->execute();
  $pedidoId = (int)$conn->insert_id;
  $stmt->close();

  $stmtDetalle = $conn->prepare(
    "INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  foreach ($carrito['items'] as $item) {
    $stmtDetalle->bind_param(
      'iisidd',
      $pedidoId, $item['producto_id'], $item['nombre'],
      $item['cantidad'], $item['precio_unitario'], $item['subtotal_item']
    );
    $stmtDetalle->execute();
  }
  $stmtDetalle->close();

  return $pedidoId;
}

function actualizarEstadoPedido($conn, $pedidoId, $estado)
{
  $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
  $stmt->bind_param('si', $estado, $pedidoId);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

/**
 * Notificacion de MercadoPago (Webhooks e IPN).
 * Nunca se confia en el contenido de la notificacion: solo se usa el id del pago
 * para consultarlo en la API con el access token de la tienda.
 */
function webhookMercadoPago($conn)
{
  $body = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($body)) {
    $body = [];
  }

  // PHP convierte el parametro "data.id" del query string en "data_id"
  $tipo = (string)($_GET['type'] ?? $body['type'] ?? $_GET['topic'] ?? $body['topic'] ?? '');
  $paymentId = (string)($_GET['data_id'] ?? $body['data']['id'] ?? '');
  if ($paymentId === '' && $tipo === 'payment') {
    $paymentId = (string)($_GET['id'] ?? '');
  }

  // merchant_order y otros temas no se procesan; 200 para que no se reintenten
  if ($tipo !== 'payment' || $paymentId === '') {
    jsonResponse(['success' => true, 'ignorado' => true]);
  }

  if (!preg_match('/^\d{1,30}$/', $paymentId)) {
    jsonResponse(['success' => false, 'error' => 'Id de pago invalido'], 400);
  }

  if (!firmaWebhookValida($paymentId)) {
    error_log('pagos.php webhook: firma invalida para el pago ' . $paymentId);
    jsonResponse(['success' => false, 'error' => 'Firma invalida'], 401);
  }

  $resultado = procesarPagoMercadoPago($conn, $paymentId);

  // Una respuesta 5xx hace que MercadoPago reintente la notificacion
  jsonResponse($resultado, !empty($resultado['reintentar']) ? 500 : 200);
}

/**
 * Valida la cabecera x-signature con MERCADOPAGO_WEBHOOK_SECRET.
 * Si no hay secreto configurado o la notificacion no trae firma (formato IPN),
 * se acepta: el pago igualmente se verifica contra la API de MercadoPago.
 */
function firmaWebhookValida($dataId)
{
  $secreto = trim((string)(getenv('MERCADOPAGO_WEBHOOK_SECRET') ?: ''));
  $cabecera = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');

  if ($secreto === '' || $cabecera === '') {
    return true;
  }

  $ts = '';
  $v1 = '';
  foreach (explode(',', $cabecera) as $parte) {
    $kv = explode('=', trim($parte), 2);
    if (count($kv) !== 2) {
      continue;
    }
    if ($kv[0] === 'ts') {
      $ts = $kv[1];
    } elseif ($kv[0] === 'v1') {
      $v1 = $kv[1];
    }
  }

  if ($ts === '' || $v1 === '') {
    return false;
  }

  $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
  $manifest = 'id:' . strtolower($dataId) . ';';
  if ($requestId !== '') {
    $manifest .= 'request-id:' . $requestId . ';';
  }
  $manifest .= 'ts:' . $ts . ';';

  return hash_equals(hash_hmac('sha256', $manifest, $secreto), $v1);
}

/**
 * Consulta el pago en MercadoPago y aplica su estado al pedido.
 * Idempotente: el pedido se confirma (stock, carrito y puntos) una sola vez,
 * protegido por el bloqueo FOR UPDATE y por las claves unicas de puntos.
 *
 * @param string|null $numeroEsperado si se indica, el pago debe pertenecer a ese pedido
 */
function procesarPagoMercadoPago($conn, $paymentId, $numeroEsperado = null)
{
  $accessToken = obtenerAccessTokenMercadoPago();
  if ($accessToken === '') {
    return ['success' => false, 'error' => 'Falta configurar credencial de MercadoPago', 'reintentar' => true];
  }

  $consulta = consultarPagoMercadoPago($accessToken, $paymentId);
  if (!$consulta['success']) {
    // 404: el pago no existe para esta cuenta; no tiene sentido reintentar
    return [
      'success' => false,
      'error' => $consulta['error'],
      'reintentar' => (int)$consulta['status'] !== 404
    ];
  }

  $pago = $consulta['data'];
  $numeroPedido = (string)($pago['external_reference'] ?? '');
  $estadoMP = (string)($pago['status'] ?? '');
  $detalleMP = (string)($pago['status_detail'] ?? '');

  if ($numeroPedido === '') {
    return ['success' => true, 'ignorado' => true, 'motivo' => 'Pago sin external_reference'];
  }

  if ($numeroEsperado !== null && $numeroPedido !== $numeroEsperado) {
    return ['success' => false, 'error' => 'El pago no corresponde a este pedido'];
  }

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare(
      "SELECT id, numero_pedido, usuario_id, total, estado, metodo_pago
       FROM pedidos WHERE numero_pedido = ? FOR UPDATE"
    );
    $stmt->bind_param('s', $numeroPedido);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pedido) {
      $conn->rollback();
      return ['success' => true, 'ignorado' => true, 'motivo' => 'Pedido no encontrado'];
    }

    $pagoLocalId = registrarPagoRecibido($conn, $numeroPedido, $paymentId, $estadoMP, $detalleMP, $pago);

    $resultado = [
      'success' => true,
      'pedido' => $numeroPedido,
      'estado_pago' => $estadoMP,
      'estado_pedido' => $pedido['estado']
    ];

    if ($estadoMP === 'approved' && $pedido['estado'] === 'cancelado') {
      // Pago aprobado de un pedido ya cancelado (p. ej. por abandono): revision manual
      error_log("pagos.php: pago $paymentId aprobado para el pedido CANCELADO $numeroPedido; requiere revision");
      $resultado['alerta'] = 'Pago aprobado para un pedido cancelado; requiere revision manual';
    }

    if ($estadoMP === 'approved' && $pedido['metodo_pago'] === 'contraentrega' && $pedido['estado'] !== 'cancelado') {
      // Enlace de cobro: el stock ya se desconto al crear el pedido; solo se registra
      // el pago y se dan los puntos (una vez por pedido)
      $monto = (float)($pago['transaction_amount'] ?? 0);
      $moneda = (string)($pago['currency_id'] ?? '');
      if ($moneda !== TIENDA_MONEDA || $monto + 0.01 < (float)$pedido['total']) {
        $conn->commit();
        error_log("pagos.php: cobro $paymentId con monto/moneda distinto al pedido contra entrega $numeroPedido");
        $resultado['alerta'] = 'El monto o la moneda del pago no coinciden con el pedido';
        return $resultado;
      }
      if ($pedido['estado'] === 'pendiente') {
        actualizarEstadoPedido($conn, (int)$pedido['id'], 'confirmado');
        $resultado['estado_pedido'] = 'confirmado';
      }
      $resultado['cobro'] = true;
      $resultado['puntos_cobro'] = asignarPuntosCompra($conn, $pedido, $pagoLocalId);
    }

    if ($estadoMP === 'approved' && $pedido['estado'] === 'pendiente' && $pedido['metodo_pago'] !== 'contraentrega') {
      $monto = (float)($pago['transaction_amount'] ?? 0);
      $moneda = (string)($pago['currency_id'] ?? '');

      if ($moneda !== TIENDA_MONEDA || $monto + 0.01 < (float)$pedido['total']) {
        // Se registra el pago pero el pedido queda pendiente para revision manual
        $conn->commit();
        error_log("pagos.php: pago $paymentId aprobado con monto/moneda distinto al pedido $numeroPedido");
        $resultado['alerta'] = 'El monto o la moneda del pago no coinciden con el pedido';
        return $resultado;
      }

      confirmarPedidoPagado($conn, $pedido);
      $resultado['puntos'] = asignarPuntosCompra($conn, $pedido, $pagoLocalId);
      $resultado['estado_pedido'] = 'confirmado';
    }

    $conn->commit();

    // Solo la llamada que confirmo el pedido envia el correo (una vez por pedido)
    if (isset($resultado['puntos'])) {
      enviarCorreosPedido($conn, (int)$pedido['id']);
    }

    return $resultado;
  } catch (Exception $e) {
    $conn->rollback();
    error_log('pagos.php procesarPagoMercadoPago: ' . $e->getMessage());
    return ['success' => false, 'error' => 'Error interno al procesar el pago', 'reintentar' => true];
  }
}

function consultarPagoMercadoPago($accessToken, $paymentId)
{
  $ch = curl_init('https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$paymentId));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);

  $response = curl_exec($ch);
  $curlError = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($curlError) {
    return ['success' => false, 'status' => 500, 'error' => 'Error de conexion con MercadoPago: ' . $curlError];
  }

  $data = json_decode((string)$response, true);
  if ($httpCode === 200 && is_array($data) && isset($data['id'])) {
    return ['success' => true, 'status' => 200, 'data' => $data];
  }

  return [
    'success' => false,
    'status' => $httpCode > 0 ? $httpCode : 502,
    'error' => is_array($data) ? (string)($data['message'] ?? 'Error consultando el pago') : 'Respuesta invalida de MercadoPago'
  ];
}

/**
 * Guarda en pagos_tienda el pago recibido para el pedido. Un pago ya aprobado
 * no se reemplaza por otro intento distinto del mismo pedido.
 * Devuelve el id local del intento de pago (o null si no existe).
 */
function registrarPagoRecibido($conn, $numeroPedido, $paymentId, $estadoMP, $detalleMP, $pago)
{
  $stmt = $conn->prepare("SELECT id FROM pagos_tienda WHERE external_reference = ? ORDER BY id DESC LIMIT 1");
  $stmt->bind_param('s', $numeroPedido);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    return null;
  }

  $pagoLocalId = (int)$row['id'];
  $resumenPago = json_encode([
    'id' => $pago['id'] ?? null,
    'status' => $pago['status'] ?? null,
    'status_detail' => $pago['status_detail'] ?? null,
    'transaction_amount' => $pago['transaction_amount'] ?? null,
    'currency_id' => $pago['currency_id'] ?? null,
    'payment_method_id' => $pago['payment_method_id'] ?? null,
    'payment_type_id' => $pago['payment_type_id'] ?? null,
    'date_approved' => $pago['date_approved'] ?? null,
    'live_mode' => $pago['live_mode'] ?? null
  ], JSON_UNESCAPED_UNICODE);
  $detalle = substr($detalleMP, 0, 120);

  $stmt = $conn->prepare(
    "UPDATE pagos_tienda
     SET payment_id = ?, estado = ?, detalle_estado = ?,
         payload_json = JSON_SET(COALESCE(payload_json, '{}'), '$.pago_mercadopago', JSON_EXTRACT(?, '$'))
     WHERE id = ? AND NOT (estado = 'approved' AND payment_id <> ?)"
  );
  $stmt->bind_param('ssssis', $paymentId, $estadoMP, $detalle, $resumenPago, $pagoLocalId, $paymentId);
  $stmt->execute();
  $stmt->close();

  return $pagoLocalId;
}

/**
 * Pago aprobado: confirma el pedido, descuenta stock y quita del carrito
 * los productos comprados. Debe llamarse dentro de la transaccion con el pedido bloqueado.
 */
function confirmarPedidoPagado($conn, $pedido)
{
  $pedidoId = (int)$pedido['id'];
  $usuarioId = (int)$pedido['usuario_id'];

  actualizarEstadoPedido($conn, $pedidoId, 'confirmado');

  $stmt = $conn->prepare(
    "UPDATE productos p
     JOIN detalle_pedido d ON d.producto_id = p.id
     SET p.stock = GREATEST(CAST(p.stock AS SIGNED) - d.cantidad, 0)
     WHERE d.pedido_id = ?"
  );
  $stmt->bind_param('i', $pedidoId);
  $stmt->execute();
  $stmt->close();

  $stmt = $conn->prepare(
    "DELETE c FROM carrito c
     JOIN detalle_pedido d ON d.producto_id = c.producto_id
     WHERE d.pedido_id = ? AND c.usuario_id = ?"
  );
  $stmt->bind_param('ii', $pedidoId, $usuarioId);
  $stmt->execute();
  $stmt->close();
}

/**
 * Estado del pedido al volver de MercadoPago (confirmacion.html).
 * Si llega payment_id y el pedido sigue pendiente, se verifica el pago en la API
 * por si la notificacion del webhook aun no ha llegado.
 */
function estadoPedidoPago($conn)
{
  if (!usuarioLogueado()) {
    jsonResponse(['success' => false, 'error' => 'Debe iniciar sesion', 'requiere_login' => true], 401);
  }

  $numeroPedido = sanitizar((string)($_GET['pedido'] ?? ''));
  $paymentId = (string)($_GET['payment_id'] ?? '');
  $usuarioId = (int)obtenerUsuarioId($conn, $_SESSION['email']);

  $pedido = obtenerPedidoUsuario($conn, $numeroPedido, $usuarioId);
  if (!$pedido) {
    jsonResponse(['success' => false, 'error' => 'Pedido no encontrado'], 404);
  }

  if ($pedido['estado'] === 'pendiente' && preg_match('/^\d{1,30}$/', $paymentId)) {
    procesarPagoMercadoPago($conn, $paymentId, $numeroPedido);
    $pedido = obtenerPedidoUsuario($conn, $numeroPedido, $usuarioId);
  }

  $stmt = $conn->prepare(
    "SELECT estado, detalle_estado FROM pagos_tienda
     WHERE external_reference = ? ORDER BY id DESC LIMIT 1"
  );
  $stmt->bind_param('s', $numeroPedido);
  $stmt->execute();
  $pago = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  jsonResponse([
    'success' => true,
    'pedido' => [
      'numero' => $pedido['numero_pedido'],
      'estado' => $pedido['estado'],
      'estado_texto' => ucfirst($pedido['estado']),
      'metodo_pago' => $pedido['metodo_pago'],
      'total' => (float)$pedido['total'],
      'total_formateado' => formatearPrecio($pedido['total'])
    ],
    'pago' => [
      'estado' => $pago['estado'] ?? null,
      'detalle' => $pago['detalle_estado'] ?? null
    ]
  ]);
}

function obtenerPedidoUsuario($conn, $numeroPedido, $usuarioId)
{
  $stmt = $conn->prepare(
    "SELECT id, numero_pedido, estado, metodo_pago, total
     FROM pedidos WHERE numero_pedido = ? AND usuario_id = ? LIMIT 1"
  );
  $stmt->bind_param('si', $numeroPedido, $usuarioId);
  $stmt->execute();
  $pedido = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $pedido;
}

// ==================== ENLACE DE COBRO (CONTRA ENTREGA) ====================

/**
 * Pagina del enlace de cobro de un pedido contra entrega (HTML, no JSON).
 * GET pedido + t (token, ver cobros-helpers.php):
 * - muestra el pedido y el boton "Pagar"; &ir=1 crea la preferencia y redirige a MercadoPago
 *   (la preferencia se crea al pulsar, no al abrir: las vistas previas de WhatsApp no la crean).
 * - &retorno=1 (vuelta de MercadoPago con payment_id): verifica el pago y muestra el resultado.
 * - pedido ya pagado: "Pago recibido", no deja cobrar dos veces.
 */
function paginaCobroPedido($conn)
{
  header('Content-Type: text/html; charset=utf-8');
  header('X-Robots-Tag: noindex, nofollow');

  $numero = preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['pedido'] ?? ''));
  $token = (string)($_GET['t'] ?? '');
  if ($numero === '' || !tokenCobroValido($numero, $token)) {
    http_response_code(404);
    mostrarPaginaCobro('Enlace no válido', '<p>Este enlace de pago no es válido. Pide uno nuevo a la tienda.</p>');
  }

  $pedido = pedidoParaCobro($conn, $numero);
  if (!$pedido) {
    http_response_code(404);
    mostrarPaginaCobro('Enlace no válido', '<p>No encontramos este pedido.</p>');
  }
  if ($pedido['metodo_pago'] !== 'contraentrega') {
    mostrarPaginaCobro('Pedido ' . $numero, '<p>Este pedido no se paga con enlace de cobro.</p>');
  }

  // Vuelta de MercadoPago: se verifica el pago en la API (por si el webhook aun no llega)
  $aviso = '';
  if (!empty($_GET['retorno'])) {
    $paymentId = (string)($_GET['payment_id'] ?? ($_GET['collection_id'] ?? ''));
    if (preg_match('/^\d{1,30}$/', $paymentId)) {
      procesarPagoMercadoPago($conn, $paymentId, $numero);
      $pedido = pedidoParaCobro($conn, $numero);
    }
    $estadoRetorno = (string)($_GET['status'] ?? ($_GET['collection_status'] ?? ''));
    if (in_array($estadoRetorno, ['pending', 'in_process'], true)) {
      $aviso = '<p class="aviso">Tu pago quedó <b>en proceso</b>. Cuando MercadoPago lo apruebe, el pedido quedará pagado.</p>';
    } elseif ($estadoRetorno !== '' && $estadoRetorno !== 'approved') {
      $aviso = '<p class="aviso error">El pago no se completó. Puedes intentarlo de nuevo.</p>';
    }
  }

  $total = formatearPrecio($pedido['total']);
  $resumen = '<p class="pedido">Pedido <b>' . htmlspecialchars($numero) . '</b><br>'
    . htmlspecialchars(html_entity_decode((string)$pedido['nombre_envio'], ENT_QUOTES, 'UTF-8')) . '</p>';

  $pago = pagoAprobadoPedido($conn, $numero);
  if ($pago) {
    mostrarPaginaCobro('Pago recibido', $resumen
      . '<p class="ok">✅ Este pedido ya está pagado</p>'
      . '<p class="total">' . $total . '</p>'
      . '<p>Medio: ' . htmlspecialchars($pago['medio']) . '</p>');
  }
  if ($pedido['estado'] === 'cancelado') {
    mostrarPaginaCobro('Pedido cancelado', $resumen . '<p>Este pedido fue cancelado; no hay nada que pagar.</p>');
  }
  if ((float)$pedido['total'] <= 0) {
    mostrarPaginaCobro('Pedido ' . $numero, $resumen . '<p>Este pedido no tiene valor por pagar.</p>');
  }

  if (!empty($_GET['ir'])) {
    $urlPago = crearPreferenciaCobro($conn, $pedido);
    if ($urlPago !== '') {
      header('Location: ' . $urlPago, true, 302);
      exit;
    }
    $aviso = '<p class="aviso error">No pudimos conectar con MercadoPago. Intenta de nuevo en un momento.</p>';
  }

  $urlIr = urlCobroPedido($numero) . '&ir=1';
  mostrarPaginaCobro('Pagar pedido ' . $numero, $resumen . $aviso
    . '<p class="total">' . $total . '</p>'
    . '<a class="boton" href="' . htmlspecialchars($urlIr) . '">Pagar con MercadoPago</a>'
    . '<p class="nota">Tarjeta de crédito o débito, PSE o saldo de MercadoPago.</p>');
}

function pedidoParaCobro($conn, $numero)
{
  $stmt = $conn->prepare(
    "SELECT id, numero_pedido, usuario_id, total, estado, metodo_pago, nombre_envio
     FROM pedidos WHERE numero_pedido = ? LIMIT 1"
  );
  $stmt->bind_param('s', $numero);
  $stmt->execute();
  $pedido = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $pedido;
}

/**
 * Crea la preferencia de MercadoPago por el total del pedido contra entrega y devuelve
 * la URL de pago ('' si falla). Reutiliza el registro de pagos_tienda del enlace si existe.
 */
function crearPreferenciaCobro($conn, $pedido)
{
  $accessToken = obtenerAccessTokenMercadoPago();
  if ($accessToken === '') {
    return '';
  }

  $numero = (string)$pedido['numero_pedido'];
  $total = round((float)$pedido['total'], 2);
  $urlCobro = urlCobroPedido($numero);
  $vencimiento = date('Y-m-d\TH:i:s.vP', time() + MP_HORAS_EXPIRACION * 3600);
  $idempotencyKey = construirIdempotencyKey((int)$pedido['usuario_id'], $total);

  $payload = [
    'items' => [[
      'id' => 'pedido-' . $numero,
      'title' => 'Compra Tu Cultura (pedido ' . $numero . ')',
      'description' => 'Pago del pedido contra entrega',
      'quantity' => 1,
      'currency_id' => TIENDA_MONEDA,
      'unit_price' => $total
    ]],
    'external_reference' => $numero,
    'statement_descriptor' => 'TU CULTURA',
    'back_urls' => [
      'success' => $urlCobro . '&retorno=1',
      'pending' => $urlCobro . '&retorno=1',
      'failure' => $urlCobro . '&retorno=1'
    ],
    'auto_return' => 'approved',
    'notification_url' => urlPublicaTienda() . '/php/pagos.php?action=webhook_mercadopago',
    'expires' => true,
    'expiration_date_to' => $vencimiento,
    'date_of_expiration' => $vencimiento,
    'metadata' => [
      'modulo' => '05-tienda',
      'numero_pedido' => $numero,
      'origen' => 'cobro_contraentrega'
    ]
  ];

  $stmt = $conn->prepare(
    "SELECT id FROM pagos_tienda
     WHERE external_reference = ? AND metodo_pago = 'cobro_mp' AND estado <> 'approved'
     ORDER BY id DESC LIMIT 1"
  );
  $stmt->bind_param('s', $numero);
  $stmt->execute();
  $fila = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $pagoLocalId = $fila ? (int)$fila['id'] : insertarIntentoPago(
    $conn,
    (int)$pedido['id'],
    (int)$pedido['usuario_id'],
    'cobro_mp',
    'pendiente_preferencia',
    null,
    $numero,
    $idempotencyKey,
    $total,
    TIENDA_MONEDA,
    'Enlace de cobro contra entrega',
    ['preference_payload' => $payload]
  );
  if (!$pagoLocalId) {
    return '';
  }

  $respuesta = crearPreferenciaMercadoPago($accessToken, $payload, $idempotencyKey);
  if (!$respuesta['success']) {
    error_log('pagos.php cobrar ' . $numero . ': ' . $respuesta['error']);
    return '';
  }
  $pref = $respuesta['data'];
  actualizarIntentoPago(
    $conn,
    $pagoLocalId,
    'preferencia_creada',
    (string)$pref['id'],
    'Enlace de cobro: preferencia creada',
    ['preference_payload' => $payload, 'mercadopago_response' => ['id' => $pref['id'] ?? null]]
  );

  return (string)($pref['init_point'] ?? ($pref['sandbox_init_point'] ?? ''));
}

/** Pagina simple (celular primero) para el enlace de cobro; termina la ejecucion. */
function mostrarPaginaCobro($titulo, $contenido)
{
  $logo = rtrim(BASE_URL, '/') . '/../01-principal/imagenes/LogoTCPCircle.png';
  echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex, nofollow">'
    . '<title>' . htmlspecialchars($titulo) . ' - Tu Cultura</title>'
    . '<style>'
    . 'body{margin:0;font-family:Arial,sans-serif;background:#f0f2f5;color:#333}'
    . '.caja{max-width:420px;margin:24px auto;background:#fff;border-radius:12px;padding:24px 20px;'
    . 'box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center}'
    . 'img{width:64px;height:64px}h1{color:#023859;font-size:1.3rem;margin:.5rem 0 1rem}'
    . '.total{font-size:2rem;font-weight:bold;color:#023859;margin:.5rem 0 1.2rem}'
    . '.boton{display:block;background:#009ee3;color:#fff;text-decoration:none;padding:14px;border-radius:8px;font-weight:bold;font-size:1.05rem}'
    . '.nota{color:#777;font-size:.85rem}.ok{color:#15803d;font-weight:bold;font-size:1.1rem}'
    . '.aviso{background:#fff7e6;border-radius:6px;padding:10px}.aviso.error{background:#fdecea;color:#b42318}'
    . '</style></head><body><div class="caja">'
    . '<img src="' . htmlspecialchars($logo) . '" alt="Tu Cultura">'
    . '<h1>' . htmlspecialchars($titulo) . '</h1>'
    . $contenido
    . '</div></body></html>';
  exit;
}

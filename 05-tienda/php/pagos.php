<?php
/**
 * API de Pagos (MercadoPago)
 * Paso 2: crear preferencia en sandbox sin romper flujo existente.
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$action = isset($_GET['action']) ? sanitizar($_GET['action']) : '';

switch ($action) {
  case 'crear_preferencia':
    crearPreferenciaSandbox($conn);
    break;
  case 'webhook_mercadopago':
    webhookMercadoPagoStub();
    break;
  default:
    jsonResponse(['success' => false, 'error' => 'Accion no valida'], 400);
}

function webhookMercadoPagoStub()
{
  jsonResponse([
    'success' => true,
    'mensaje' => 'Webhook pendiente de implementacion',
    'pendiente' => true
  ]);
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

  $carrito = obtenerCarritoParaPago($conn, (int)$usuarioId);
  if (empty($carrito['items'])) {
    jsonResponse([
      'success' => false,
      'error' => 'El carrito esta vacio',
      'carrito_vacio' => true
    ], 400);
  }

  $input = obtenerEntradaCheckout();
  $externalReference = construirExternalReference($usuarioId);
  $idempotencyKey = construirIdempotencyKey($usuarioId, $carrito['total']);
  $urls = construirUrlsRetorno();

  $payloadPreferencia = [
    'items' => array_values($carrito['items_mp']),
    'payer' => [
      'name' => (string)($input['nombre'] !== '' ? $input['nombre'] : ($usuario['nombres'] ?? '')),
      'surname' => (string)($input['apellido'] !== '' ? $input['apellido'] : ($usuario['apellidos'] ?? '')),
      'email' => (string)($input['email'] !== '' ? $input['email'] : ($usuario['email'] ?? '')),
      'phone' => [
        'number' => (string)($input['telefono'] !== '' ? $input['telefono'] : ($usuario['telefono'] ?? ''))
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
    'metadata' => [
      'modulo' => '05-tienda',
      'usuario_id' => (int)$usuarioId,
      'usuario_email' => (string)$usuario['email'],
      'origen' => 'checkout_tienda',
      'direccion_envio' => [
        'direccion' => $input['direccion'],
        'ciudad' => $input['ciudad'],
        'departamento' => $input['departamento'],
        'codigo_postal' => $input['codigo_postal']
      ]
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

  $pagoLocalId = insertarIntentoPago(
    $conn,
    null,
    (int)$usuarioId,
    'mercadopago',
    'pendiente_preferencia',
    null,
    $externalReference,
    $idempotencyKey,
    (float)$carrito['total'],
    TIENDA_MONEDA,
    'Creacion de preferencia (sandbox)',
    $payloadRegistro
  );

  if (!$pagoLocalId) {
    jsonResponse([
      'success' => false,
      'error' => 'No fue posible registrar el intento de pago'
    ], 500);
  }

  $respuestaMP = crearPreferenciaMercadoPago($accessToken, $payloadPreferencia, $idempotencyKey);

  if (!$respuestaMP['success']) {
    actualizarIntentoPago(
      $conn,
      (int)$pagoLocalId,
      'error_preferencia',
      null,
      (string)$respuestaMP['error'],
      ['mercadopago_error' => $respuestaMP]
    );

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
    'codigo_postal' => sanitizar((string)($input['codigo_postal'] ?? ''))
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

function construirUrlsRetorno()
{
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

  $scheme = $isHttps ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $basePublic = rtrim($scheme . '://' . $host . BASE_URL, '/');

  return [
    'success' => $basePublic . '/confirmacion.html',
    'pending' => $basePublic . '/checkout.html?pago=pendiente',
    'failure' => $basePublic . '/checkout.html?pago=fallido',
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

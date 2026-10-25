<?php

/**
 * Enlace de cobro de pedidos contra entrega (MercadoPago)
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Cada pedido contra entrega tiene un enlace fijo
 * (pagos.php?action=cobrar&pedido=NUMERO&t=TOKEN) que el admin comparte con el
 * transportista y el cliente (enlace o QR) y que tambien va en el correo del pedido.
 * El token es un HMAC del numero de pedido: el enlace no se puede adivinar ni
 * fabricar para otro pedido. Secreto: TIENDA_COBRO_SECRET o, si no existe, DB_PASSWORD.
 *
 * Lo incluyen pagos.php, admin.php y correo-pedidos.php. No ejecuta nada por si solo.
 */

if (!function_exists('secretoCobro')) {
  function secretoCobro(): string
  {
    $base = getenv('TIENDA_COBRO_SECRET') ?: (getenv('DB_PASSWORD') ?: '');
    return $base === '' ? '' : hash('sha256', 'tcp-cobro|' . $base);
  }
}

if (!function_exists('tokenCobroPedido')) {
  /** Token del enlace de cobro ('' si no hay secreto configurado). */
  function tokenCobroPedido(string $numeroPedido): string
  {
    $secreto = secretoCobro();
    return $secreto === '' ? '' : substr(hash_hmac('sha256', 'cobro|' . $numeroPedido, $secreto), 0, 24);
  }
}

if (!function_exists('tokenCobroValido')) {
  function tokenCobroValido(string $numeroPedido, string $token): bool
  {
    $esperado = tokenCobroPedido($numeroPedido);
    return $esperado !== '' && hash_equals($esperado, $token);
  }
}

if (!function_exists('urlPublicaTienda')) {
  /** URL publica de 05-tienda (https detras de Caddy, ver docker/apache/tucultura.conf). */
  function urlPublicaTienda(): string
  {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim(($isHttps ? 'https' : 'http') . '://' . $host . BASE_URL, '/');
  }
}

if (!function_exists('urlCobroPedido')) {
  /** Enlace de cobro del pedido ('' si no hay secreto configurado). */
  function urlCobroPedido(string $numeroPedido): string
  {
    $token = tokenCobroPedido($numeroPedido);
    if ($token === '') return '';
    return urlPublicaTienda() . '/php/pagos.php?action=cobrar&pedido=' . rawurlencode($numeroPedido) . '&t=' . $token;
  }
}

if (!function_exists('pagoAprobadoPedido')) {
  /**
   * Pago de MercadoPago aprobado del pedido (pagos_tienda), o null.
   * Devuelve id, payment_id, metodo_pago, monto, medio (texto: "PSE", "Tarjeta de credito"...).
   */
  function pagoAprobadoPedido($conn, string $numeroPedido): ?array
  {
    try {
      $stmt = $conn->prepare(
        "SELECT id, payment_id, metodo_pago, monto,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.pago_mercadopago.payment_type_id')) AS tipo,
                JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.pago_mercadopago.payment_method_id')) AS metodo
         FROM pagos_tienda WHERE external_reference = ? AND estado = 'approved'
         ORDER BY id DESC LIMIT 1"
      );
      $stmt->bind_param('s', $numeroPedido);
      $stmt->execute();
      $fila = $stmt->get_result()->fetch_assoc();
      $stmt->close();
    } catch (Throwable $e) {
      return null;
    }
    if (!$fila) return null;
    $fila['medio'] = textoMedioPagoMP((string)$fila['tipo'], (string)$fila['metodo']);
    return $fila;
  }
}

if (!function_exists('textoMedioPagoMP')) {
  /** Medio de pago de MercadoPago legible (payment_type_id / payment_method_id). */
  function textoMedioPagoMP(string $tipo, string $metodo = ''): string
  {
    $tipos = [
      'credit_card' => 'Tarjeta de crédito',
      'debit_card' => 'Tarjeta débito',
      'prepaid_card' => 'Tarjeta prepago',
      'bank_transfer' => 'PSE',
      'ticket' => 'Efectivo',
      'atm' => 'Efectivo',
      'account_money' => 'Dinero en cuenta MercadoPago',
      'digital_currency' => 'Crédito MercadoPago',
    ];
    $texto = $tipos[$tipo] ?? 'MercadoPago';
    if ($tipo === 'ticket' && $metodo !== '') {
      $texto .= ' (' . ucfirst($metodo) . ')';
    }
    return $texto;
  }
}

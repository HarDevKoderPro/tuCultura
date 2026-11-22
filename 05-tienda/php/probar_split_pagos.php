<?php

/**
 * Pruebas del reparto de pagos (split-pagos-helpers.php) sin base de datos.
 *
 * Solo CLI. En el VPS:
 *   docker exec tucultura-app-1 php /var/www/html/05-tienda/php/probar_split_pagos.php
 * Sale con codigo 1 si alguna prueba falla.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/split-pagos-helpers.php';

$fallos = 0;
$total = 0;

function probar(string $nombre, callable $prueba): void
{
  global $fallos, $total;
  $total++;
  try {
    $prueba();
    echo "OK    $nombre\n";
  } catch (Throwable $e) {
    $fallos++;
    echo "FALLA $nombre -> " . $e->getMessage() . "\n";
  }
}

function esperar($real, $esperado, string $que = ''): void
{
  if ($real !== $esperado) {
    throw new Exception(($que !== '' ? "$que: " : '') . 'esperado ' . var_export($esperado, true) . ', real ' . var_export($real, true));
  }
}

function debeFallar(callable $f, string $fragmento = ''): void
{
  try {
    $f();
  } catch (SplitException $e) {
    if ($fragmento !== '' && strpos($e->getMessage(), $fragmento) === false) {
      throw new Exception('fallo con otro mensaje: ' . $e->getMessage());
    }
    return;
  }
  throw new Exception('debia lanzar SplitException y no lo hizo');
}

/** Montos por actor: ['proveedor:1' => 60000, ...] */
function montos(array $lineas): array
{
  $r = [];
  foreach ($lineas as $l) {
    $r[$l['actor_tipo'] . ':' . $l['actor_id']] = $l['monto'];
  }
  return $r;
}

$proveedores = [
  1 => ['nombre' => 'Proveedor A', 'porcentaje_proveedor' => 60],
  2 => ['nombre' => 'Proveedor B', 'porcentaje_proveedor' => 50],
  3 => ['nombre' => 'Proveedor C', 'porcentaje_proveedor' => 95],
];
$referente = ['usuario_id' => 7, 'nombre' => 'Maria G.'];
$mensajeria = ['id' => 1, 'nombre' => 'Mensajeria X'];

// ---------------- calcularSplit ----------------

probar('Ejemplo del negocio: $100.000, proveedor 60%, referente 10%, tienda 30%', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 10000, 'tienda:0' => 30000]);
});

probar('Con mensajeria: el envio completo va a la mensajeria', function () use ($proveedores, $referente, $mensajeria) {
  $l = calcularSplit(
    ['total' => 115000, 'subtotal' => 100000, 'costo_envio' => 15000],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10, ['mensajeria' => $mensajeria]
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 10000, 'mensajeria:1' => 15000, 'tienda:0' => 30000]);
});

probar('Sin mensajeria el envio queda en la tienda', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 115000, 'subtotal' => 100000, 'costo_envio' => 15000],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10
  );
  esperar(montos($l)['tienda:0'], 45000);
});

probar('Sin referente su porcentaje queda en la tienda', function () use ($proveedores) {
  $l = calcularSplit(
    ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, null, 10
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'tienda:0' => 40000]);
});

probar('Varios proveedores en un pedido (cada uno cobra solo de SUS productos)', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 200000, 'subtotal' => 200000, 'costo_envio' => 0],
    [
      ['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1],
      ['producto_id' => 2, 'subtotal' => 60000, 'proveedor_id' => 2],
      ['producto_id' => 3, 'subtotal' => 40000, 'proveedor_id' => 0],
    ],
    $proveedores, $referente, 10
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'proveedor:2' => 30000, 'referente:7' => 20000, 'tienda:0' => 90000]);
});

probar('Producto propio: todo para la tienda salvo el referente', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 50000, 'subtotal' => 50000, 'costo_envio' => 0],
    [['producto_id' => 4, 'subtotal' => 50000, 'proveedor_id' => 0]],
    $proveedores, $referente, 10
  );
  esperar(montos($l), ['referente:7' => 5000, 'tienda:0' => 45000]);
});

probar('Con bono: el descuento sale del margen de la tienda', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 90000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 10000, 'tienda:0' => 20000]);
});

probar('Redondeo: la suma siempre es igual al total', function () use ($proveedores, $referente) {
  $proveedoresRaros = [1 => ['nombre' => 'P', 'porcentaje_proveedor' => 33.33]];
  $l = calcularSplit(
    ['total' => 33337, 'subtotal' => 33337, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 33337, 'proveedor_id' => 1]],
    $proveedoresRaros, $referente, 10
  );
  esperar(array_sum(array_column($l, 'monto')), 33337);
  foreach ($l as $linea) {
    esperar(is_int($linea['monto']), true, 'monto entero');
  }
});

probar('Margen negativo (proveedor 95% + referente 10%) se rechaza', function () use ($proveedores, $referente) {
  debeFallar(function () use ($proveedores, $referente) {
    calcularSplit(
      ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
      [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 3]],
      $proveedores, $referente, 10
    );
  }, 'Margen negativo');
});

probar('Subtotal que no coincide con los productos se rechaza', function () use ($proveedores) {
  debeFallar(function () use ($proveedores) {
    calcularSplit(
      ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
      [['producto_id' => 1, 'subtotal' => 90000, 'proveedor_id' => 1]],
      $proveedores, null, 10
    );
  }, 'no coincide');
});

probar('Proveedor inexistente se rechaza', function () use ($proveedores) {
  debeFallar(function () use ($proveedores) {
    calcularSplit(
      ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
      [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 99]],
      $proveedores, null, 10
    );
  }, 'no existe');
});

probar('Mas de 10 destinatarios secundarios se rechaza', function () {
  $muchos = [];
  $items = [];
  for ($i = 1; $i <= 11; $i++) {
    $muchos[$i] = ['nombre' => "P$i", 'porcentaje_proveedor' => 5];
    $items[] = ['producto_id' => $i, 'subtotal' => 10000, 'proveedor_id' => $i];
  }
  debeFallar(function () use ($muchos, $items) {
    calcularSplit(['total' => 110000, 'subtotal' => 110000, 'costo_envio' => 0], $items, $muchos, null, 0);
  }, 'Maximo');
});

probar('Total en cero se rechaza', function () use ($proveedores) {
  debeFallar(function () use ($proveedores) {
    calcularSplit(['total' => 0, 'subtotal' => 0, 'costo_envio' => 0], [], $proveedores, null, 10);
  });
});

// ---- Referente con % distinto para productos fisicos y digitales ----

probar('Referente por tipo: pedido mixto usa el % de cada tipo (10% fisicos, 5% digitales)', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 150000, 'subtotal' => 150000, 'costo_envio' => 0],
    [
      ['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1, 'es_digital' => false],
      ['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 0, 'es_digital' => true],
    ],
    $proveedores, $referente, ['fisico' => 10, 'digital' => 5]
  );
  // referente = 100000 x 10% + 50000 x 5% = 12.500; proveedor 60% de 100000
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 12500, 'tienda:0' => 77500]);
  foreach ($l as $linea) {
    if ($linea['actor_tipo'] === 'referente') {
      esperar($linea['descripcion'], 'Referente directo (10% fisicos / 5% digitales)');
    }
  }
});

probar('Referente por tipo: pedido solo digital usa el % digital', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 50000, 'subtotal' => 50000, 'costo_envio' => 0],
    [['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 0, 'es_digital' => true]],
    $proveedores, $referente, ['fisico' => 10, 'digital' => 5]
  );
  esperar(montos($l), ['referente:7' => 2500, 'tienda:0' => 47500]);
  esperar($l[0]['descripcion'], 'Referente directo (5%)');
});

probar('Referente por tipo: digital ausente vale lo mismo que fisico', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 50000, 'subtotal' => 50000, 'costo_envio' => 0],
    [['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 0, 'es_digital' => true]],
    $proveedores, $referente, ['fisico' => 10]
  );
  esperar(montos($l)['referente:7'], 5000);
});

probar('Referente por tipo: un numero suelto vale para fisicos y digitales (compatibilidad)', function () use ($proveedores, $referente) {
  $items = [
    ['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1, 'es_digital' => false],
    ['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 0, 'es_digital' => true],
  ];
  $pedido = ['total' => 150000, 'subtotal' => 150000, 'costo_envio' => 0];
  $conNumero = calcularSplit($pedido, $items, $proveedores, $referente, 10);
  $conArreglo = calcularSplit($pedido, $items, $proveedores, $referente, ['fisico' => 10, 'digital' => 10]);
  esperar($conNumero, $conArreglo);
  esperar(montos($conNumero)['referente:7'], 15000);
});

probar('Referente por tipo: el proveedor cobra su % tambien sobre productos digitales', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 50000, 'subtotal' => 50000, 'costo_envio' => 0],
    [['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 1, 'es_digital' => true]],
    $proveedores, $referente, ['fisico' => 10, 'digital' => 5]
  );
  esperar(montos($l), ['proveedor:1' => 30000, 'referente:7' => 2500, 'tienda:0' => 17500]);
});

probar('Referente por tipo: porcentaje digital fuera de 0-100 se rechaza', function () use ($proveedores, $referente) {
  debeFallar(function () use ($proveedores, $referente) {
    calcularSplit(
      ['total' => 50000, 'subtotal' => 50000, 'costo_envio' => 0],
      [['producto_id' => 2, 'subtotal' => 50000, 'proveedor_id' => 0, 'es_digital' => true]],
      $proveedores, $referente, ['fisico' => 10, 'digital' => 150]
    );
  }, 'entre 0 y 100');
});

// ---- Costos de la venta: comision de la pasarela y pago fijo a la mensajeria ----

$comisionEjemplo = ['porcentaje' => 3, 'fijo' => 900, 'iva' => 19, 'nombre' => 'MercadoPago'];

probar('Ejemplo completo: proveedor 60%, referente 10%, mensajeria fija y comision salen del resto', function () use ($proveedores, $referente, $comisionEjemplo) {
  $l = calcularSplit(
    ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10,
    ['mensajeria' => ['id' => 1, 'nombre' => 'Mensajeria X', 'monto' => 12000], 'comision' => $comisionEjemplo]
  );
  // comision = (100000 x 3% + 900) x 1,19 = 4.641; tienda = 100000 - 60000 - 10000 - 12000 - 4641
  esperar(montos($l), [
    'proveedor:1' => 60000, 'referente:7' => 10000, 'mensajeria:1' => 12000, 'comision:0' => 4641, 'tienda:0' => 13359,
  ]);
  esperar(array_sum(array_column($l, 'monto')), 100000);
});

probar('Mensajeria con monto fijo no usa el costo de envio del cliente', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 115000, 'subtotal' => 100000, 'costo_envio' => 15000],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10,
    ['mensajeria' => ['id' => 1, 'nombre' => 'Mensajeria X', 'monto' => 12000]]
  );
  // El envio cobrado (15000) queda en el resto de la tienda: 115000 - 60000 - 10000 - 12000
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 10000, 'mensajeria:1' => 12000, 'tienda:0' => 33000]);
});

probar('Sin comision configurada no se descuenta nada (comportamiento anterior)', function () use ($proveedores, $referente) {
  $l = calcularSplit(
    ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10,
    ['comision' => ['porcentaje' => 0, 'fijo' => 0, 'iva' => 19]]
  );
  esperar(montos($l), ['proveedor:1' => 60000, 'referente:7' => 10000, 'tienda:0' => 30000]);
});

probar('Costos que superan el resto de la tienda: se rechaza por defecto', function () use ($proveedores, $referente, $comisionEjemplo) {
  debeFallar(function () use ($proveedores, $referente, $comisionEjemplo) {
    calcularSplit(
      ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
      [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
      $proveedores, $referente, 10,
      ['mensajeria' => ['id' => 1, 'nombre' => 'M', 'monto' => 30000], 'comision' => $comisionEjemplo]
    );
  }, 'Margen negativo');
});

probar('Vista previa del admin: muestra la perdida de la tienda (margen negativo permitido)', function () use ($proveedores, $referente, $comisionEjemplo) {
  $l = calcularSplit(
    ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
    [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
    $proveedores, $referente, 10,
    ['mensajeria' => ['id' => 1, 'nombre' => 'M', 'monto' => 30000], 'comision' => $comisionEjemplo, 'permitir_margen_negativo' => true]
  );
  esperar(montos($l)['tienda:0'], -4641);
  esperar(array_sum(array_column($l, 'monto')), 100000);
});

probar('Proveedor + referente mayores al total se rechaza aun en vista previa', function () use ($proveedores, $referente) {
  debeFallar(function () use ($proveedores, $referente) {
    calcularSplit(
      ['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0],
      [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 3]],
      $proveedores, $referente, 10, ['permitir_margen_negativo' => true]
    );
  }, 'Margen negativo');
});

probar('La comision no cuenta como destinatario secundario (limite de 10)', function () use ($comisionEjemplo) {
  $diez = [];
  $items = [];
  for ($i = 1; $i <= 10; $i++) {
    $diez[$i] = ['nombre' => "P$i", 'porcentaje_proveedor' => 5];
    $items[] = ['producto_id' => $i, 'subtotal' => 10000, 'proveedor_id' => $i];
  }
  $l = calcularSplit(['total' => 100000, 'subtotal' => 100000, 'costo_envio' => 0], $items, $diez, null, 0, ['comision' => $comisionEjemplo]);
  esperar(array_sum(array_column($l, 'monto')), 100000);
});

// ---------------- MockProvider ----------------

$pedido = ['numero_pedido' => 'TCP-PRUEBA-1', 'total' => 115000];
$splitsBase = calcularSplit(
  ['total' => 115000, 'subtotal' => 100000, 'costo_envio' => 15000],
  [['producto_id' => 1, 'subtotal' => 100000, 'proveedor_id' => 1]],
  $proveedores, $referente, 10, ['mensajeria' => $mensajeria]
);

probar('Mock: orden aprobada con su reparto', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $r = $p->crearOrden($pedido, $splitsBase);
  esperar($r['estado'], 'approved');
  esperar($r['repetida'], false);
  $c = $p->consultarOrden($r['orden_id']);
  esperar($c['total'], 115000);
  esperar(count($c['splits']), 4);
});

probar('Mock: crear dos veces es idempotente (no cobra doble)', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $a = $p->crearOrden($pedido, $splitsBase);
  $b = $p->crearOrden($pedido, $splitsBase);
  esperar($b['orden_id'], $a['orden_id']);
  esperar($b['repetida'], true);
});

probar('Mock: reparto que no suma el total se rechaza', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $mal = $splitsBase;
  $mal[0]['monto'] += 1;
  debeFallar(function () use ($p, $pedido, $mal) { $p->crearOrden($pedido, $mal); }, 'no suma');
});

probar('Mock: pago rechazado', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $r = $p->crearOrden($pedido, $splitsBase, ['simular' => 'rejected']);
  esperar($r['estado'], 'rejected');
  debeFallar(function () use ($p, $r) { $p->reembolsar($r['orden_id'], [['actor_tipo' => 'tienda', 'actor_id' => 0, 'monto' => 1]]); }, 'aprobada');
});

probar('Mock: pendiente que luego se aprueba (webhook)', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $r = $p->crearOrden($pedido, $splitsBase, ['simular' => 'pending']);
  esperar($r['estado'], 'pending');
  $p->simularResolucion($r['orden_id'], 'approved');
  esperar($p->consultarOrden($r['orden_id'])['estado'], 'approved');
  debeFallar(function () use ($p, $r) { $p->simularResolucion($r['orden_id'], 'rejected'); });
});

probar('Mock: reembolso parcial y luego total, por actor', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $id = $p->crearOrden($pedido, $splitsBase)['orden_id'];

  $r = $p->reembolsar($id, [['actor_tipo' => 'proveedor', 'actor_id' => 1, 'monto' => 60000]]);
  esperar($r['estado'], 'partially_refunded');
  esperar($r['reembolsado_total'], 60000);

  // No se puede devolver mas de lo que recibio el proveedor
  debeFallar(function () use ($p, $id) {
    $p->reembolsar($id, [['actor_tipo' => 'proveedor', 'actor_id' => 1, 'monto' => 1]]);
  }, 'mas de lo que recibio');

  // El resto de los actores completan el reembolso total
  $r = $p->reembolsar($id, [
    ['actor_tipo' => 'referente', 'actor_id' => 7, 'monto' => 10000],
    ['actor_tipo' => 'mensajeria', 'actor_id' => 1, 'monto' => 15000],
    ['actor_tipo' => 'tienda', 'actor_id' => 0, 'monto' => 30000],
  ]);
  esperar($r['estado'], 'refunded');
  esperar($r['reembolsado_total'], 115000);
});

probar('Mock: reembolso invalido no aplica nada (todo o nada)', function () use ($pedido, $splitsBase) {
  MockProvider::reiniciar();
  $p = new MockProvider();
  $id = $p->crearOrden($pedido, $splitsBase)['orden_id'];
  debeFallar(function () use ($p, $id) {
    $p->reembolsar($id, [
      ['actor_tipo' => 'tienda', 'actor_id' => 0, 'monto' => 1000],
      ['actor_tipo' => 'proveedor', 'actor_id' => 1, 'monto' => 999999],
    ]);
  });
  esperar($p->consultarOrden($id)['reembolsado_total'], 0);
  esperar($p->consultarOrden($id)['estado'], 'approved');
});

probar('Mock: webhook con firma valida pasa y con firma falsa no', function () {
  $p = new MockProvider();
  $cuerpo = '{"orden":"mock-TCP-PRUEBA-1","estado":"approved"}';
  esperar($p->verificarWebhook(['X-Signature' => MockProvider::firmarWebhook($cuerpo)], $cuerpo), true, 'firma valida');
  esperar($p->verificarWebhook(['X-Signature' => 'falsa'], $cuerpo), false, 'firma falsa');
  esperar($p->verificarWebhook([], $cuerpo), false, 'sin firma');
  esperar($p->verificarWebhook(['x-signature' => MockProvider::firmarWebhook($cuerpo)], $cuerpo . ' '), false, 'cuerpo alterado');
});

probar('Selector: PAYMENT_PROVIDER=mock por defecto y pasarela real aun no implementada', function () {
  putenv('PAYMENT_PROVIDER');
  esperar(pasarelaPagoSplit() instanceof MockProvider, true);
});

echo "\n" . ($total - $fallos) . " de $total pruebas correctas\n";
exit($fallos > 0 ? 1 : 0);

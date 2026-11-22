<?php

/**
 * Pruebas del envio gratis por rangos (tipos-producto-helpers.php) sin base de datos.
 *
 * Solo CLI. En el VPS:
 *   docker exec tucultura-app-1 php /var/www/html/05-tienda/php/probar_envio_rangos.php
 * Sale con codigo 1 si alguna prueba falla.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

// Mismos valores que config.php (que no se incluye aqui porque abre la conexion a la BD)
define('TIENDA_COSTO_ENVIO_BASE', 15000);
define('TIENDA_ENVIO_GRATIS_MINIMO', 20000);

require_once __DIR__ . '/tipos-producto-helpers.php';

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

function conError($entrada, string $fragmento): void
{
  $r = validarRangosEnvio($entrada);
  if (!isset($r['error'])) {
    throw new Exception('debia dar error y no lo dio');
  }
  if (strpos($r['error'], $fragmento) === false) {
    throw new Exception('error distinto: ' . $r['error']);
  }
}

// Rangos de ejemplo: 0-19.999 con costo, 20.000-99.999 gratis, desde 100.000 con costo
$ejemplo = [[0, 19999, 0], [20000, 99999, 1], [100000, null, 0]];

// ---------------- validarRangosEnvio ----------------

probar('Valida y ordena rangos; gratis acepta 1/0, true/false y si/no', function () {
  $r = validarRangosEnvio([
    ['desde' => '100.000', 'hasta' => '', 'gratis' => 'no'],
    ['desde' => 0, 'hasta' => 19999, 'gratis' => false],
    ['desde' => '20000', 'hasta' => '99999', 'gratis' => 'si'],
  ]);
  esperar($r, ['rangos' => [[0, 19999, 0], [20000, 99999, 1], [100000, null, 0]]]);
  esperar(validarRangosEnvio([[0, null, '1']])['rangos'], [[0, null, 1]]);
  esperar(validarRangosEnvio([])['rangos'], []);
});

probar('Rechaza rangos que se cruzan', function () {
  conError([['desde' => 0, 'hasta' => 50000, 'gratis' => 0], ['desde' => 40000, 'hasta' => '', 'gratis' => 1]], 'se cruzan');
});

probar('Rechaza "sin limite" fuera del ultimo rango', function () {
  conError([['desde' => 0, 'hasta' => '', 'gratis' => 0], ['desde' => 50000, 'hasta' => 90000, 'gratis' => 1]], 'último rango');
});

probar('Rechaza gratis vacio o invalido', function () {
  conError([['desde' => 0, 'hasta' => 100, 'gratis' => '']], 'Sí o No');
  conError([['desde' => 0, 'hasta' => 100, 'gratis' => 'quizas']], 'Sí o No');
});

probar('Rechaza hasta menor que desde, desde invalido y mas de 8 rangos', function () {
  conError([['desde' => 500, 'hasta' => 100, 'gratis' => 1]], 'no puede ser menor');
  conError([['desde' => 'abc', 'hasta' => 100, 'gratis' => 1]], '"Desde"');
  $nueve = [];
  for ($i = 0; $i < 9; $i++) {
    $nueve[] = ['desde' => $i * 1000, 'hasta' => $i * 1000 + 999, 'gratis' => 1];
  }
  conError($nueve, 'máximo');
});

// ---------------- costo del envio ----------------

probar('Sin rangos rige la regla de siempre: gratis desde $20.000', function () {
  esperar(costoEnvioConRangos(19999, []), 15000.0);
  esperar(costoEnvioConRangos(20000, []), 0.0);
  esperar(costoEnvioConRangos(500000, []), 0.0);
});

probar('Sin productos fisicos nunca hay envio (solo digitales)', function () use ($ejemplo) {
  esperar(costoEnvioConRangos(0, []), 0.0);
  esperar(costoEnvioConRangos(0, $ejemplo), 0.0);
});

probar('Con rangos: cada valor cae en su rango (limites incluidos)', function () use ($ejemplo) {
  esperar(costoEnvioConRangos(10000, $ejemplo), 15000.0);
  esperar(costoEnvioConRangos(19999, $ejemplo), 15000.0);
  esperar(costoEnvioConRangos(20000, $ejemplo), 0.0);
  esperar(costoEnvioConRangos(99999, $ejemplo), 0.0);
  esperar(costoEnvioConRangos(100000, $ejemplo), 15000.0);
  esperar(costoEnvioConRangos(900000, $ejemplo), 15000.0);
});

probar('Un valor fuera de todos los rangos NO es gratis', function () {
  $rangos = [[50000, null, 1]];
  esperar(costoEnvioConRangos(30000, $rangos), 15000.0);
  esperar(costoEnvioConRangos(50000, $rangos), 0.0);
});

probar('Costo del envio configurable: lo paga el cliente cuando no es gratis', function () use ($ejemplo) {
  // Sin rangos: bajo $20.000 paga el costo configurado
  esperar(costoEnvioConRangos(10000, [], 10000.0), 10000.0);
  esperar(costoEnvioConRangos(20000, [], 10000.0), 0.0);
  // Con rangos: el costo configurado solo aplica donde el envio no es gratis
  esperar(costoEnvioConRangos(10000, $ejemplo, 10000.0), 10000.0);
  esperar(costoEnvioConRangos(50000, $ejemplo, 10000.0), 0.0);
  esperar(costoEnvioConRangos(150000, $ejemplo, 10000.0), 10000.0);
});

probar('Sin costo configurado se usa el de siempre ($15.000)', function () use ($ejemplo) {
  esperar(costoEnvioConRangos(10000, [], null), 15000.0);
  esperar(costoEnvioConRangos(10000, $ejemplo), 15000.0);
});

// ---------------- cuanto falta para el envio gratis ----------------

probar('Falta para el envio gratis (regla de siempre)', function () {
  esperar(faltaEnvioGratisConRangos(15000, []), 5000.0);
  esperar(faltaEnvioGratisConRangos(20000, []), 0.0);
  esperar(faltaEnvioGratisConRangos(0, []), 0.0);
});

probar('Falta para el envio gratis con rangos', function () use ($ejemplo) {
  esperar(faltaEnvioGratisConRangos(10000, $ejemplo), 10000.0);   // llega a 20.000
  esperar(faltaEnvioGratisConRangos(20000, $ejemplo), 0.0);       // ya es gratis
  esperar(faltaEnvioGratisConRangos(150000, $ejemplo), 0.0);      // paga y no hay rango gratis mas arriba
  esperar(faltaEnvioGratisConRangos(30000, [[50000, null, 1]]), 20000.0);
});

probar('Valor desde el que el envio es gratis', function () use ($ejemplo) {
  esperar(minimoEnvioGratisConRangos([]), 20000.0);
  esperar(minimoEnvioGratisConRangos($ejemplo), 20000.0);
  esperar(minimoEnvioGratisConRangos([[0, null, 0]]), 0.0);
});

probar('Los rangos validados se usan tal cual en el calculo', function () {
  $r = validarRangosEnvio([['desde' => '30.000', 'hasta' => '', 'gratis' => '1']]);
  esperar(costoEnvioConRangos(29999, $r['rangos']), 15000.0);
  esperar(costoEnvioConRangos(30000, $r['rangos']), 0.0);
});

echo "\n" . ($total - $fallos) . " de $total pruebas correctas\n";
exit($fallos > 0 ? 1 : 0);

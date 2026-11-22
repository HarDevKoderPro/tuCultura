<?php
/**
 * Tipo de producto (fisico / digital) en carrito, checkout y pedidos.
 *
 * Reglas (opcion A del plan de productos digitales):
 * - Un digital no usa stock (se trata como ilimitado y nunca se descuenta), ni envio, ni direccion.
 * - El envio y el envio gratis se calculan solo con los productos FISICOS del carrito.
 * - Si el carrito trae algun digital, solo se aceptan pagos anticipados (no contra entrega).
 *
 * Todo funciona aunque la columna productos.tipo_producto aun no exista (la agrega el
 * admin al usar el tipo): en ese caso todos los productos son fisicos. Las consultas de
 * lectura NUNCA hacen ALTER TABLE (haria commit implicito dentro de una transaccion);
 * solo asegurarColumnaTipoProducto() lo hace y debe llamarse fuera de transacciones.
 *
 * Usa mysqli ($conn). Lo incluyen carrito.php, pedidos.php, pagos.php y admin.php.
 */

const STOCK_DIGITAL_ILIMITADO = 999999;

function existeColumnaTipoProducto(mysqli $conn, bool $volverAComprobar = false): bool
{
  static $existe = null;
  if ($existe === null || $volverAComprobar) {
    $r = $conn->query("SHOW COLUMNS FROM productos LIKE 'tipo_producto'");
    $existe = (bool)($r && $r->num_rows > 0);
  }
  return $existe;
}

/** Agrega la columna si falta (DDL: no llamar dentro de una transaccion). */
function asegurarColumnaTipoProducto(mysqli $conn): void
{
  if (existeColumnaTipoProducto($conn)) return;
  $conn->query("ALTER TABLE productos ADD COLUMN tipo_producto VARCHAR(10) NOT NULL DEFAULT 'fisico'");
  existeColumnaTipoProducto($conn, true);
}

function normalizarTipoProducto($valor)
{
  return $valor === 'digital' ? 'digital' : 'fisico';
}

/** Expresion SQL del tipo de un producto ('fisico' si la columna aun no existe). */
function exprTipoProducto(mysqli $conn, string $prefijo = 'p.'): string
{
  return existeColumnaTipoProducto($conn) ? $prefijo . 'tipo_producto' : "'fisico'";
}

/** Expresion SQL del stock: ilimitado para los digitales. */
function exprStockEfectivo(mysqli $conn, string $prefijo = 'p.'): string
{
  if (!existeColumnaTipoProducto($conn)) return $prefijo . 'stock';
  return "IF({$prefijo}tipo_producto = 'digital', " . STOCK_DIGITAL_ILIMITADO . ", {$prefijo}stock)";
}

/*
 * Envio gratis por rangos (configuracion_tienda.envio_rangos, editable en el dashboard del admin).
 * Segun el valor de los productos FISICOS del pedido, el envio es gratis (1) o no (0); cuando no es
 * gratis se cobra TIENDA_COSTO_ENVIO_BASE. Un valor que no cae en ningun rango NO es gratis.
 * Sin rangos configurados rige la regla de siempre: gratis desde TIENDA_ENVIO_GRATIS_MINIMO.
 * Formato guardado: JSON [[desde, hasta|null, gratis 0/1], ...]. La columna valor es VARCHAR(255):
 * por eso el maximo de rangos y de montos.
 */
const ENVIO_RANGOS_MAXIMO = 8;
const ENVIO_RANGOS_MONTO_MAXIMO = 999999999;

/**
 * Valida y normaliza los rangos de envio recibidos del admin.
 * Acepta filas {desde, hasta, gratis} o [desde, hasta, gratis]; hasta vacio = sin limite;
 * gratis: 1/0, true/false o si/no. Devuelve ['rangos' => [[desde, hasta, gratis], ...]] ordenados
 * por desde, o ['error' => mensaje].
 */
function validarRangosEnvio($entrada): array
{
  if (!is_array($entrada)) {
    return ['error' => 'Formato de rangos no válido'];
  }
  if (count($entrada) > ENVIO_RANGOS_MAXIMO) {
    return ['error' => 'Se permiten máximo ' . ENVIO_RANGOS_MAXIMO . ' rangos'];
  }

  $entero = function ($v) {
    $v = trim(str_replace(['.', ',', '$', ' '], '', (string)$v));
    return preg_match('/^\d{1,10}$/', $v) ? (int)$v : null;
  };

  $rangos = [];
  foreach (array_values($entrada) as $i => $fila) {
    $n = $i + 1;
    if (!is_array($fila)) {
      return ['error' => "Rango $n: formato no válido"];
    }
    $desde = $entero($fila['desde'] ?? ($fila[0] ?? null));
    if ($desde === null || $desde > ENVIO_RANGOS_MONTO_MAXIMO) {
      return ['error' => "Rango $n: \"Desde\" debe ser un valor en pesos entre 0 y 999.999.999"];
    }

    $hasta = $fila['hasta'] ?? ($fila[1] ?? null);
    if ($hasta === null || trim((string)$hasta) === '') {
      $hasta = null;
    } else {
      $hasta = $entero($hasta);
      if ($hasta === null || $hasta > ENVIO_RANGOS_MONTO_MAXIMO) {
        return ['error' => "Rango $n: \"Hasta\" debe ser un valor en pesos hasta 999.999.999, o vacío para sin límite"];
      }
      if ($hasta < $desde) {
        return ['error' => "Rango $n: \"Hasta\" no puede ser menor que \"Desde\""];
      }
    }

    $gratis = $fila['gratis'] ?? ($fila[2] ?? null);
    $texto = strtolower(trim((string)(is_bool($gratis) ? ($gratis ? '1' : '0') : $gratis)));
    if (in_array($texto, ['1', 'si', 'sí', 'true'], true)) {
      $gratis = 1;
    } elseif (in_array($texto, ['0', 'no', 'false'], true)) {
      $gratis = 0;
    } else {
      return ['error' => "Rango $n: indica si el envío es gratis (Sí o No)"];
    }

    $rangos[] = [$desde, $hasta, $gratis];
  }

  usort($rangos, function ($a, $b) {
    return $a[0] <=> $b[0];
  });

  $total = count($rangos);
  for ($i = 0; $i < $total; $i++) {
    if ($rangos[$i][1] === null && $i < $total - 1) {
      return ['error' => 'Solo el último rango (el de mayor valor) puede quedar sin límite'];
    }
    if ($i > 0 && $rangos[$i][0] <= $rangos[$i - 1][1]) {
      return ['error' => 'Los rangos se cruzan: revise el que empieza en $' . number_format($rangos[$i][0], 0, ',', '.')];
    }
  }

  return ['rangos' => $rangos];
}

/** Rangos de envio configurados ([] = sin configurar o valor guardado no valido). */
function rangosEnvio(mysqli $conn): array
{
  try {
    $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'envio_rangos' LIMIT 1");
    $fila = $res ? $res->fetch_assoc() : null;
  } catch (Throwable $e) {
    return [];
  }
  if (!$fila) return [];
  $validacion = validarRangosEnvio(json_decode((string)$fila['valor'], true));
  return $validacion['rangos'] ?? [];
}

/**
 * Costo del envio que paga el cliente cuando no es gratis (configuracion_tienda.envio_costo,
 * editable en el dashboard). Sin definir (o 0) = TIENDA_COSTO_ENVIO_BASE.
 * No confundir con split_pago_mensajeria: eso es lo que la tienda le paga a la mensajeria.
 */
function costoEnvioBase(?mysqli $conn): float
{
  if ($conn) {
    try {
      $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'envio_costo' LIMIT 1");
      $fila = $res ? $res->fetch_assoc() : null;
      if ($fila && is_numeric($fila['valor']) && (float)$fila['valor'] > 0) {
        return (float)$fila['valor'];
      }
    } catch (Throwable $e) {
      // La tabla puede no existir todavia: se usa el valor por defecto
    }
  }
  return (float)TIENDA_COSTO_ENVIO_BASE;
}

/**
 * Costo de envio segun los rangos (funcion pura). $rangos vacio = regla de siempre.
 * Solo cuenta el valor de los productos fisicos (0 si no hay fisicos).
 * $costoBase = lo que paga el cliente cuando no es gratis (null = TIENDA_COSTO_ENVIO_BASE).
 */
function costoEnvioConRangos(float $subtotalFisico, array $rangos, ?float $costoBase = null): float
{
  if ($subtotalFisico <= 0) return 0.0;
  $costo = $costoBase ?? (float)TIENDA_COSTO_ENVIO_BASE;

  if (!$rangos) {
    return $subtotalFisico >= TIENDA_ENVIO_GRATIS_MINIMO ? 0.0 : $costo;
  }
  foreach ($rangos as $rango) {
    $dentro = $subtotalFisico >= $rango[0] && ($rango[1] === null || $subtotalFisico <= $rango[1]);
    if ($dentro) {
      return $rango[2] ? 0.0 : $costo;
    }
  }
  return $costo; // fuera de todos los rangos: no es gratis
}

/**
 * Cuanto le falta al comprador para llegar a un rango de envio gratis (funcion pura).
 * 0 si ya es gratis, si no hay productos fisicos o si no existe un rango gratis mas arriba.
 */
function faltaEnvioGratisConRangos(float $subtotalFisico, array $rangos): float
{
  if ($subtotalFisico <= 0) return 0.0;
  if (costoEnvioConRangos($subtotalFisico, $rangos) <= 0) return 0.0;

  if (!$rangos) {
    return max(0.0, (float)TIENDA_ENVIO_GRATIS_MINIMO - $subtotalFisico);
  }
  foreach ($rangos as $rango) { // ordenados por desde
    if ($rango[2] && $rango[0] > $subtotalFisico) {
      return (float)($rango[0] - $subtotalFisico);
    }
  }
  return 0.0;
}

/** Valor desde el cual el envio es gratis (el menor "desde" de los rangos gratis; 0 si ninguno). */
function minimoEnvioGratisConRangos(array $rangos): float
{
  if (!$rangos) return (float)TIENDA_ENVIO_GRATIS_MINIMO;
  foreach ($rangos as $rango) {
    if ($rango[2]) return (float)$rango[0];
  }
  return 0.0;
}

/**
 * Costo de envio: solo cuenta el subtotal de los productos fisicos (0 si no hay fisicos).
 * Con $conn usa los rangos del admin; sin $conn (o sin rangos) la regla de siempre.
 */
function costoEnvioFisicos(float $subtotalFisico, ?mysqli $conn = null): float
{
  return costoEnvioConRangos($subtotalFisico, $conn ? rangosEnvio($conn) : [], costoEnvioBase($conn));
}

/** Lo que falta para el envio gratis (ver faltaEnvioGratisConRangos). */
function faltaEnvioGratis(float $subtotalFisico, ?mysqli $conn = null): float
{
  return faltaEnvioGratisConRangos($subtotalFisico, $conn ? rangosEnvio($conn) : []);
}

/**
 * Cuantos productos fisicos y digitales hay en el carrito de un usuario.
 * Devuelve ['total' => n, 'fisicos' => n, 'digitales' => n, 'solo_digital' => bool].
 */
function resumenTiposCarritoUsuario(mysqli $conn, int $usuarioId): array
{
  $tipo = exprTipoProducto($conn, 'p.');
  $stmt = $conn->prepare(
    "SELECT COUNT(*) AS total, COALESCE(SUM($tipo = 'digital'), 0) AS digitales
     FROM carrito c JOIN productos p ON c.producto_id = p.id
     WHERE c.usuario_id = ? AND p.activo = 1"
  );
  $stmt->bind_param('i', $usuarioId);
  $stmt->execute();
  $fila = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $total = (int)($fila['total'] ?? 0);
  $digitales = (int)($fila['digitales'] ?? 0);
  return [
    'total' => $total,
    'fisicos' => $total - $digitales,
    'digitales' => $digitales,
    'solo_digital' => $total > 0 && $digitales === $total
  ];
}

/**
 * Datos de envio que se guardan cuando el pedido es solo de productos digitales.
 */
function datosEnvioDigital(): array
{
  return [
    'direccion' => 'Producto digital (sin envío)',
    'ciudad' => '-',
    'departamento' => '-',
    'codigo_postal' => ''
  ];
}

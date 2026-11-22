<?php

/**
 * Reparto (split) de cada venta entre proveedor, referente, mensajeria y tienda.
 * Tu Cultura es Progreso - Tienda Virtual
 *
 * Paso 1 (ver SPLIT_PAGOS.md): solo logica pura y un proveedor de pago simulado.
 * NO ejecuta nada por si solo, NO toca la BD y NO esta conectado a pagos.php ni a ningun
 * flujo existente: se incluye explicitamente desde quien lo necesite.
 *
 * Nombres: en este proyecto "proveedor" es quien surte el producto (tabla proveedores).
 * La pasarela se llama aqui "pasarela" (interfaz PasarelaPagoSplit); la implementacion
 * simulada se llama MockProvider, como pide la especificacion.
 *
 * Reglas de calculo (acordadas el 2026-11-09, ver CLAUDE.md seccion 14):
 * - Proveedor: su % acordado sobre el valor de SUS productos (proveedores.porcentaje_proveedor).
 * - Referente directo del comprador: % global sobre el valor de los productos
 *   (configuracion_tienda.porcentaje_referente); sin referente, ese % queda en la tienda.
 * - Mensajeria: un pago por pedido fisico (valor fijo configurable, o el costo de envio si no se
 *   indica uno), solo si se indica una mensajeria; si no, el envio queda en la tienda.
 * - Comision de la pasarela: (total x % + fijo) x (1 + IVA), estimada, configurable en el admin.
 * - Tienda: el resto del total. Si hay bono, su descuento sale del margen de la tienda
 *   (el proveedor y el referente cobran sobre el valor de los productos antes del bono).
 *   La comision y la mensajeria tambien salen de ese margen.
 * - Todo en pesos COP enteros (sin decimales). La tienda absorbe el redondeo, por eso la
 *   suma de las lineas es SIEMPRE igual al total del pedido.
 *
 * El proveedor nunca se expone al publico: estas lineas son solo para el backend y el admin.
 */

if (!class_exists('SplitException')) {
  /** Errores de regla del reparto (montos que no cuadran, margen negativo, etc.). */
  class SplitException extends Exception {}
}

if (!defined('SPLIT_MAX_SECUNDARIOS')) {
  /** Maximo de destinatarios distintos de la tienda por orden (limite de Mercado Pago). */
  define('SPLIT_MAX_SECUNDARIOS', 10);
}

if (!function_exists('calcularSplit')) {
  /**
   * Calcula las lineas del reparto de un pedido. Funcion pura: recibe todo por parametro.
   *
   * @param array $pedido      ['total' => int, 'subtotal' => int, 'costo_envio' => int]
   *                           total = lo que paga el comprador (ya con el descuento del bono);
   *                           subtotal = valor de los productos (sin envio, antes del bono).
   * @param array $items       lista de ['producto_id' => int, 'subtotal' => int, 'proveedor_id' => int,
   *                           'es_digital' => bool (opcional, por defecto fisico)]
   *                           (proveedor_id 0 = "Tienda (propio)"). La suma debe ser igual al subtotal.
   * @param array $proveedores [proveedor_id => ['nombre' => string, 'porcentaje_proveedor' => float]]
   * @param array|null $referente ['usuario_id' => int, 'nombre' => string] o null si no tiene
   * @param float|array $porcentajeReferente % del referente (0-100): un numero para todos los productos
   *                           o ['fisico' => float, 'digital' => float] (digital ausente = igual al fisico)
   * @param array $opciones    'mensajeria' => ['id' => int, 'nombre' => string, 'monto' => int (opcional)]
   *                           (sin 'monto' se paga el costo de envio del pedido),
   *                           'comision' => ['porcentaje' => float, 'fijo' => int, 'iva' => float, 'nombre' => string],
   *                           'permitir_margen_negativo' => bool (solo vista previa del admin)
   * @return array lista de lineas ['actor_tipo' (proveedor|referente|mensajeria|comision|tienda),
   *               'actor_id', 'nombre', 'monto' (int COP), 'descripcion', 'destino' (null: lo
   *               completa la pasarela real)]. No incluye lineas en cero. La linea 'comision' es un
   *               costo del cobro, no un destinatario: una pasarela real no la recibe como split.
   * @throws SplitException
   */
  function calcularSplit(
    array $pedido,
    array $items,
    array $proveedores,
    ?array $referente,
    $porcentajeReferente,
    array $opciones = []
  ): array {
    $total = (int)($pedido['total'] ?? 0);
    $subtotal = (int)($pedido['subtotal'] ?? 0);
    $costoEnvio = max(0, (int)($pedido['costo_envio'] ?? 0));

    if ($total <= 0) {
      throw new SplitException('El total del pedido debe ser mayor a cero');
    }
    if ($subtotal < 0) {
      throw new SplitException('El subtotal del pedido no es valido');
    }
    // % del referente por tipo de producto: un numero vale para ambos; digital ausente = fisico
    if (is_array($porcentajeReferente)) {
      $pctFisico = (float)($porcentajeReferente['fisico'] ?? 0);
      $pctDigital = (float)($porcentajeReferente['digital'] ?? $pctFisico);
    } else {
      $pctFisico = $pctDigital = (float)$porcentajeReferente;
    }
    if ($pctFisico < 0 || $pctFisico > 100 || $pctDigital < 0 || $pctDigital > 100) {
      throw new SplitException('El porcentaje del referente debe estar entre 0 y 100');
    }

    // 1. Valor de los productos por proveedor (los propios no generan linea) y por tipo
    $sumaItems = 0;
    $subtotalFisico = 0;
    $subtotalDigital = 0;
    $porProveedor = [];
    foreach ($items as $item) {
      $monto = (int)($item['subtotal'] ?? 0);
      if ($monto < 0) {
        throw new SplitException('Un producto del pedido tiene subtotal negativo');
      }
      $sumaItems += $monto;
      if (!empty($item['es_digital'])) {
        $subtotalDigital += $monto;
      } else {
        $subtotalFisico += $monto;
      }
      $proveedorId = (int)($item['proveedor_id'] ?? 0);
      if ($proveedorId > 0) {
        $porProveedor[$proveedorId] = ($porProveedor[$proveedorId] ?? 0) + $monto;
      }
    }
    if ($sumaItems !== $subtotal) {
      throw new SplitException(
        "La suma de los productos ($sumaItems) no coincide con el subtotal del pedido ($subtotal)"
      );
    }

    $lineas = [];
    $secundarios = 0;

    // 2. Proveedores (orden estable por id)
    ksort($porProveedor);
    foreach ($porProveedor as $proveedorId => $valorProductos) {
      if (!isset($proveedores[$proveedorId])) {
        throw new SplitException("El proveedor $proveedorId no existe");
      }
      $porcentaje = (float)($proveedores[$proveedorId]['porcentaje_proveedor'] ?? 0);
      if ($porcentaje < 0 || $porcentaje > 100) {
        throw new SplitException("El porcentaje del proveedor $proveedorId debe estar entre 0 y 100");
      }
      $monto = (int)round($valorProductos * $porcentaje / 100);
      if ($monto > 0) {
        $lineas[] = [
          'actor_tipo' => 'proveedor',
          'actor_id' => (int)$proveedorId,
          'nombre' => (string)($proveedores[$proveedorId]['nombre'] ?? ''),
          'monto' => $monto,
          'descripcion' => 'Parte del proveedor (' . rtrim(rtrim(number_format($porcentaje, 2, '.', ''), '0'), '.') . '%)',
          'destino' => null,
        ];
        $secundarios++;
      }
    }

    // 3. Referente directo del comprador
    //    Cada tipo de producto usa su porcentaje: fisicos y digitales se calculan por separado.
    if ($referente !== null) {
      $monto = (int)round($subtotalFisico * $pctFisico / 100 + $subtotalDigital * $pctDigital / 100);
      if ($monto > 0) {
        $formato = function ($p) {
          return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
        };
        // Texto del porcentaje: solo el de los tipos que hay en el pedido
        if ($subtotalFisico > 0 && $subtotalDigital > 0 && $pctFisico !== $pctDigital) {
          $textoPct = $formato($pctFisico) . '% fisicos / ' . $formato($pctDigital) . '% digitales';
        } else {
          $textoPct = $formato($subtotalFisico > 0 || $subtotalDigital === 0 ? $pctFisico : $pctDigital) . '%';
        }
        $lineas[] = [
          'actor_tipo' => 'referente',
          'actor_id' => (int)($referente['usuario_id'] ?? 0),
          'nombre' => (string)($referente['nombre'] ?? ''),
          'monto' => $monto,
          'descripcion' => 'Referente directo (' . $textoPct . ')',
          'destino' => null,
        ];
        $secundarios++;
      }
    }

    // Lo que se debe a proveedor y referente nunca puede superar el total pagado
    $sumaVenta = array_sum(array_column($lineas, 'monto'));
    if ($sumaVenta > $total) {
      throw new SplitException(
        'Margen negativo para la tienda: los repartos (' . $sumaVenta . ') superan el total (' . $total . ')'
      );
    }

    // 4. Mensajeria: pago de la mensajeria por pedido fisico. Por defecto el costo de envio
    //    cobrado al cliente; con $opciones['mensajeria']['monto'] un valor fijo propio
    //    (sale del margen de la tienda, no del cliente).
    $mensajeria = $opciones['mensajeria'] ?? null;
    if (is_array($mensajeria)) {
      $montoMensajeria = isset($mensajeria['monto']) ? (int)$mensajeria['monto'] : $costoEnvio;
      if ($montoMensajeria < 0) {
        throw new SplitException('El pago a la mensajeria no puede ser negativo');
      }
      if ($montoMensajeria > 0) {
        $lineas[] = [
          'actor_tipo' => 'mensajeria',
          'actor_id' => (int)($mensajeria['id'] ?? 0),
          'nombre' => (string)($mensajeria['nombre'] ?? ''),
          'monto' => $montoMensajeria,
          'descripcion' => 'Pago de la mensajeria',
          'destino' => null,
        ];
        $secundarios++;
      }
    }

    if ($secundarios > SPLIT_MAX_SECUNDARIOS) {
      throw new SplitException('Maximo ' . SPLIT_MAX_SECUNDARIOS . ' destinatarios secundarios por orden');
    }

    // 5. Comision de la pasarela: costo del cobro (no se le paga a nadie), sale del margen de la
    //    tienda. Se calcula sobre el total pagado: (total x % + fijo) x (1 + IVA %).
    //    Es una estimacion: la tarifa real la confirma la pasarela.
    $comision = $opciones['comision'] ?? null;
    if (is_array($comision)) {
      $comisionPct = (float)($comision['porcentaje'] ?? 0);
      $comisionFijo = (int)($comision['fijo'] ?? 0);
      $comisionIva = (float)($comision['iva'] ?? 0);
      if ($comisionPct < 0 || $comisionPct > 100 || $comisionFijo < 0 || $comisionIva < 0 || $comisionIva > 100) {
        throw new SplitException('La comision de la pasarela no es valida');
      }
      $montoComision = (int)round(($total * $comisionPct / 100 + $comisionFijo) * (1 + $comisionIva / 100));
      if ($montoComision > 0) {
        $lineas[] = [
          'actor_tipo' => 'comision',
          'actor_id' => 0,
          'nombre' => (string)($comision['nombre'] ?? 'Pasarela de pago'),
          'monto' => $montoComision,
          'descripcion' => 'Comision de la pasarela (estimada)',
          'destino' => null,
        ];
      }
    }

    // 6. La tienda recibe el resto (incluye el redondeo y absorbe el bono, la comision y la
    //    mensajeria). Si queda negativo se rechaza, salvo en la vista previa del admin
    //    ($opciones['permitir_margen_negativo']), que debe mostrar cuanto se perderia.
    $sumaSecundarios = array_sum(array_column($lineas, 'monto'));
    $margenTienda = $total - $sumaSecundarios;
    if ($margenTienda < 0 && empty($opciones['permitir_margen_negativo'])) {
      throw new SplitException(
        'Margen negativo para la tienda: los repartos (' . $sumaSecundarios . ') superan el total (' . $total . ')'
      );
    }
    if ($margenTienda !== 0) {
      $lineas[] = [
        'actor_tipo' => 'tienda',
        'actor_id' => 0,
        'nombre' => 'Tienda',
        'monto' => $margenTienda,
        'descripcion' => 'Margen de la tienda',
        'destino' => null,
      ];
    }

    // 7. Comprobacion final: la suma debe ser exactamente el total
    if (array_sum(array_column($lineas, 'monto')) !== $total) {
      throw new SplitException('El reparto no suma el total del pedido');
    }

    return $lineas;
  }
}

if (!interface_exists('PasarelaPagoSplit')) {
  /**
   * Contrato que cumple cada pasarela (simulada o real). El resto del codigo depende de esto,
   * no de una pasarela concreta, para poder cambiar de Mercado Pago a Wompi, OnePay, etc.
   * sin reescribir la tienda.
   */
  interface PasarelaPagoSplit
  {
    /**
     * Crea (o devuelve, si ya existe) la orden de un pedido con su reparto. Idempotente por pedido.
     * @param array $pedido ['numero_pedido' => string, 'total' => int]
     * @param array $splits lineas de calcularSplit()
     * @param array $datosPago datos propios de la pasarela
     * @return array ['orden_id' => string, 'estado' => string, 'repetida' => bool]
     */
    public function crearOrden(array $pedido, array $splits, array $datosPago = []): array;

    /** Estado real de la orden: ['estado', 'total', 'splits', 'reembolsado_total']. */
    public function consultarOrden(string $ordenId): array;

    /**
     * Reembolso total o parcial, indicando cuanto devuelve cada actor.
     * @param array $splits lista de ['actor_tipo' => string, 'actor_id' => int, 'monto' => int]
     * @return array ['estado' => string, 'reembolsado_total' => int]
     */
    public function reembolsar(string $ordenId, array $splits): array;

    /** Valida la firma/origen de un webhook recibido (cabeceras y cuerpo sin procesar). */
    public function verificarWebhook(array $headers, string $rawBody): bool;
  }
}

if (!class_exists('MockProvider')) {
  /**
   * Pasarela simulada para desarrollar y probar sin credenciales. Guarda las ordenes solo en
   * memoria del proceso actual (no hay BD): sirve para pruebas y para validar el flujo.
   *
   * Estados: pending, approved, rejected, partially_refunded, refunded.
   * $datosPago['simular'] = approved (por defecto) | rejected | pending.
   */
  final class MockProvider implements PasarelaPagoSplit
  {
    /** @var array<string, array> */
    private static array $ordenes = [];

    public static function reiniciar(): void
    {
      self::$ordenes = [];
    }

    /** Firma de webhook simulada (HMAC-SHA256 del cuerpo). Solo para pruebas. */
    public static function firmarWebhook(string $rawBody): string
    {
      $secreto = getenv('MOCK_WEBHOOK_SECRET') ?: 'mock-secreto-solo-pruebas';
      return hash_hmac('sha256', $rawBody, $secreto);
    }

    public function crearOrden(array $pedido, array $splits, array $datosPago = []): array
    {
      $referencia = trim((string)($pedido['numero_pedido'] ?? ''));
      if ($referencia === '') {
        throw new SplitException('Falta el numero del pedido');
      }

      $ordenId = 'mock-' . $referencia;
      if (isset(self::$ordenes[$ordenId])) {
        return ['orden_id' => $ordenId, 'estado' => self::$ordenes[$ordenId]['estado'], 'repetida' => true];
      }

      $total = (int)($pedido['total'] ?? 0);
      $suma = 0;
      foreach ($splits as $linea) {
        $monto = (int)($linea['monto'] ?? 0);
        if ($monto <= 0) {
          throw new SplitException('Cada linea del reparto debe tener un monto mayor a cero');
        }
        $suma += $monto;
      }
      if ($total <= 0 || $suma !== $total) {
        throw new SplitException("El reparto ($suma) no suma el total del pedido ($total)");
      }

      $estado = (string)($datosPago['simular'] ?? 'approved');
      if (!in_array($estado, ['approved', 'rejected', 'pending'], true)) {
        throw new SplitException('Estado simulado no valido: ' . $estado);
      }

      self::$ordenes[$ordenId] = [
        'numero_pedido' => $referencia,
        'total' => $total,
        'estado' => $estado,
        'splits' => array_values($splits),
        'reembolsos' => [],
      ];
      return ['orden_id' => $ordenId, 'estado' => $estado, 'repetida' => false];
    }

    /** Simula el aviso (webhook) de una orden pendiente que se aprueba o se rechaza despues. */
    public function simularResolucion(string $ordenId, string $estado): void
    {
      $orden = $this->obtener($ordenId);
      if ($orden['estado'] !== 'pending' || !in_array($estado, ['approved', 'rejected'], true)) {
        throw new SplitException('Solo una orden pendiente puede pasar a approved o rejected');
      }
      self::$ordenes[$ordenId]['estado'] = $estado;
    }

    public function consultarOrden(string $ordenId): array
    {
      $orden = $this->obtener($ordenId);
      return [
        'estado' => $orden['estado'],
        'total' => $orden['total'],
        'splits' => $orden['splits'],
        'reembolsado_total' => $this->totalReembolsado($orden),
      ];
    }

    public function reembolsar(string $ordenId, array $splits): array
    {
      $orden = $this->obtener($ordenId);
      if (!in_array($orden['estado'], ['approved', 'partially_refunded'], true)) {
        throw new SplitException('Solo se puede reembolsar una orden aprobada');
      }
      if (!$splits) {
        throw new SplitException('Indica cuanto devuelve cada actor');
      }

      // Cuanto queda por devolver de cada actor
      $disponible = [];
      foreach ($orden['splits'] as $linea) {
        $clave = $linea['actor_tipo'] . '|' . (int)$linea['actor_id'];
        $disponible[$clave] = ($disponible[$clave] ?? 0) + (int)$linea['monto'];
      }
      foreach ($orden['reembolsos'] as $clave => $monto) {
        $disponible[$clave] -= $monto;
      }

      // Validar todo antes de aplicar nada
      $nuevos = [];
      foreach ($splits as $linea) {
        $clave = (string)($linea['actor_tipo'] ?? '') . '|' . (int)($linea['actor_id'] ?? 0);
        $monto = (int)($linea['monto'] ?? 0);
        if (!isset($disponible[$clave])) {
          throw new SplitException("El actor $clave no participa en la orden");
        }
        if ($monto <= 0) {
          throw new SplitException('El monto a reembolsar debe ser mayor a cero');
        }
        $nuevos[$clave] = ($nuevos[$clave] ?? 0) + $monto;
        if ($nuevos[$clave] > $disponible[$clave]) {
          throw new SplitException("No se puede reembolsar mas de lo que recibio el actor $clave");
        }
      }

      foreach ($nuevos as $clave => $monto) {
        self::$ordenes[$ordenId]['reembolsos'][$clave] = (self::$ordenes[$ordenId]['reembolsos'][$clave] ?? 0) + $monto;
      }

      $reembolsado = $this->totalReembolsado(self::$ordenes[$ordenId]);
      self::$ordenes[$ordenId]['estado'] = ($reembolsado === $orden['total']) ? 'refunded' : 'partially_refunded';
      return ['estado' => self::$ordenes[$ordenId]['estado'], 'reembolsado_total' => $reembolsado];
    }

    public function verificarWebhook(array $headers, string $rawBody): bool
    {
      $cabeceras = array_change_key_case($headers, CASE_LOWER);
      $firma = (string)($cabeceras['x-signature'] ?? '');
      return $firma !== '' && hash_equals(self::firmarWebhook($rawBody), $firma);
    }

    private function obtener(string $ordenId): array
    {
      if (!isset(self::$ordenes[$ordenId])) {
        throw new SplitException('La orden no existe: ' . $ordenId);
      }
      return self::$ordenes[$ordenId];
    }

    private function totalReembolsado(array $orden): int
    {
      return (int)array_sum($orden['reembolsos']);
    }
  }
}

if (!function_exists('pasarelaPagoSplit')) {
  /**
   * Pasarela activa segun la variable de entorno PAYMENT_PROVIDER (por defecto "mock").
   * Mientras no exista una pasarela real implementada, solo se acepta "mock".
   */
  function pasarelaPagoSplit(): PasarelaPagoSplit
  {
    static $instancia = null;
    if ($instancia !== null) return $instancia;

    $nombre = strtolower(trim((string)(getenv('PAYMENT_PROVIDER') ?: 'mock')));
    switch ($nombre) {
      case 'mock':
        $instancia = new MockProvider();
        return $instancia;
      default:
        throw new SplitException('Pasarela de pago no implementada todavia: ' . $nombre);
    }
  }
}

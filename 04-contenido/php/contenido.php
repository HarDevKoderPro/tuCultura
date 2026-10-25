<?php
session_start();

if (!isset($_SESSION['user_id'])) {
  header("Location: ../../02-iniciarSesion/iniciarSesion.html");
  exit;
}

include './conexion.php';

$totalRegistros         = contarRegistros($conn);
$totalUsuario           = contarRegistrosUsuario($conn, $_SESSION['email']);
$totalReferidosDirectos = contarRegistrosDeReferidos($conn, $_SESSION['email']);
$totalReferidosNivel3   = contarReferidosNivel3($conn, $_SESSION['email']);

// Carga inicial: Nivel 1
$registrosIniciales = obtenerRegistrosNivel1($conn, $_SESSION['email']);

// Puntos acumulados por compras aprobadas (propias y de referidos nivel 1 y 2)
$puntosAcumulados = obtenerPuntosUsuario($conn, (int)$_SESSION['user_id']);
$valorPunto       = obtenerValorPunto($conn);
$bonos            = obtenerBonosUsuario($conn, (int)$_SESSION['user_id'], $puntosAcumulados);

// Las cuentas de administrador no pueden comprar en la tienda (el carrito de las tarjetas lo avisa)
$esCuentaAdmin = isset($_SESSION['rol']) && $_SESSION['rol'] !== 'cliente';
?>

<!DOCTYPE html>
<html lang='es'>

<head>
  <meta charset='UTF-8'>
  <meta name="format-detection" content="telephone=no, email=no, address=no">
  <meta http-equiv='X-UA-Compatible' content='IE=edge'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0, minimum-scale=1.0,maximum-scale=1.0'>
  <title>Contenido Usuarios</title>
  <link rel='stylesheet' href='../css/contenidoDesktop.css'>
  <link rel="stylesheet" href="../css/contenidoMobile.css">
  <link rel="stylesheet" href="../01-principal/css/fonts.css">
  <link rel="shortcut icon" href="../../01-principal/imagenes/LogoTCPCircle.png" type="image/x-icon">
</head>

<body>

  <div class="container">

    <!-- Encabezado -->
    <section class="encabezado">
      <div class="contenedorLinkSalir">
        <a href="./logout.php" class="linkSalir"><span class="icon-share iconos"></span> Salir</a>
      </div>
      <div class="contenedorDatosLogin">
        <span class="tituloPagina">Zona de Usuarios</span>
        <span class="icon-user"></span>
        <span class="nombreUsuario">
          <?php echo htmlspecialchars($_SESSION['nombres'] . ' ' . $_SESSION['apellidos']); ?>
        </span>
      </div>
      <div class="contenedorLinkEbook">
        <a href="../../04-contenido/libros/Creatividad Motivacional.epub" download="Creatividad Motivacional" class="linkEbook">Ebook <span class="icon-download iconos"></span></a>
        <a href="../../04-contenido/libros/Creatividad-Motivacional.pdf" download="Creatividad Motivacional" class="linkEbook">PDF <span class="icon-download iconos"></span></a>
      </div>
    </section>

    <!-- Sección Promocional -->
    <section class="contenidoPromocional">

      <div class="contenedorVideoPromocional">
        <div class="video">
          <video controls preload="metadata" poster="../video/Thumbnail.png">
            <source src="../video/GiraBienes.mp4" type="video/mp4">
            Tu navegador no soporta el elemento de video.
          </video>
        </div>
      </div>

      <div class="contenedorTarjetas">

        <?php
        /**
         * Tarjetas giratorias dinámicas — carga productos vinculados desde JSON + BD
         */
        $tarjetasJSON = __DIR__ . '/../../05-tienda/php/tarjetas-contenido.json';
        $productosVinculados = [];

        if (file_exists($tarjetasJSON)) {
            $jsonData = json_decode(file_get_contents($tarjetasJSON), true);
            if (isset($jsonData['productos_vinculados']) && is_array($jsonData['productos_vinculados'])) {
                $idsVinculados = $jsonData['productos_vinculados'];

                if (!empty($idsVinculados)) {
                    // Conexión a la BD de tienda (reutilizar config de tienda)
                    $tiendaConfigPath = __DIR__ . '/../../05-tienda/php/config.php';
                    if (file_exists($tiendaConfigPath)) {
                        // Usar las mismas credenciales que la tienda
                        $dbConn = new mysqli(
                            getenv('DB_HOST') ?: 'localhost',
                            getenv('DB_USER') ?: 'tucultur',
                            getenv('DB_PASSWORD') ?: '',
                            getenv('DB_NAME') ?: 'tucultur_asociados'
                        );
                        
                        if (!$dbConn->connect_error) {
                            $dbConn->set_charset("utf8mb4");
                            $placeholders = implode(',', array_fill(0, count($idsVinculados), '?'));
                            $types = str_repeat('i', count($idsVinculados));
                            
                            $stmt = $dbConn->prepare("
                                SELECT p.id, p.nombre, p.descripcion, p.imagen, p.precio, 
                                       p.precio_oferta, p.stock, p.marca,
                                       c.nombre as categoria_nombre
                                FROM productos p
                                LEFT JOIN categorias c ON p.categoria_id = c.id
                                WHERE p.id IN ($placeholders) AND p.activo = 1
                            ");
                            $stmt->bind_param($types, ...$idsVinculados);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            // Indexar por ID para mantener el orden del JSON
                            $productosMap = [];
                            while ($row = $result->fetch_assoc()) {
                                $productosMap[$row['id']] = $row;
                            }
                            
                            // Respetar el orden definido en el JSON
                            foreach ($idsVinculados as $id) {
                                if (isset($productosMap[$id])) {
                                    $productosVinculados[] = $productosMap[$id];
                                }
                            }
                            
                            $dbConn->close();
                        }
                    }
                }
            }
        }

        // Renderizar tarjetas dinámicas o placeholder
        if (!empty($productosVinculados)):
            foreach ($productosVinculados as $idx => $prod):
                $nombre = htmlspecialchars($prod['nombre']);
                $categoria = htmlspecialchars($prod['categoria_nombre'] ?? 'Producto');
                $descripcion = htmlspecialchars($prod['descripcion'] ?? '');
                $imagen = $prod['imagen'] ? '../../05-tienda/imagenes/productos/' . htmlspecialchars($prod['imagen']) : '../images/audifonosInalambricos.png';
                $precio = '$ ' . number_format($prod['precio'], 0, ',', '.');
                $precioOferta = $prod['precio_oferta'] ? '$ ' . number_format($prod['precio_oferta'], 0, ',', '.') : '';
                $stock = intval($prod['stock']);
                $marca = htmlspecialchars($prod['marca'] ?? '');
                
                // Generar specs a partir de la descripción
                // Separador: coma (,) o salto de línea — igual que en la tienda (tienda.js)
                $specs = [];
                if ($descripcion) {
                    $partes = preg_split('/,|\n|\r\n?/', $descripcion, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($partes as $parte) {
                        $parte = trim($parte);
                        if (mb_strlen($parte) > 3) $specs[] = $parte;
                    }
                }
                if (empty($specs)) {
                    $specs[] = $nombre;
                    if ($marca) $specs[] = 'Marca: ' . $marca;
                    if ($stock > 0) $specs[] = 'Disponible en stock';
                    else $specs[] = 'Consultar disponibilidad';
                }

                $envioTexto = $stock > 0 ? 'Disponible' : 'Agotado';
        ?>
        <!-- Tarjeta <?php echo ($idx + 1); ?> — Dinámica -->
        <div class="tarjeta">
          <div class="tarjeta-inner">
            <div class="tarjeta-frontal">
              <h3 class="tarjeta-titulo"><?php echo $nombre; ?></h3>
              <div class="tarjeta-imagen">
                <img src="<?php echo $imagen; ?>" alt="<?php echo $nombre; ?>" onerror="this.src='../images/audifonosInalambricos.png'">
              </div>
              <div class="tarjeta-footer">Más información ↓</div>
            </div>
            <div class="tarjeta-trasera">
              <div class="tarjeta-back-header">
                <span class="tarjeta-categoria"><?php echo $categoria; ?></span>
                <button type="button" class="tarjeta-carrito"
                        data-producto-id="<?php echo intval($prod['id']); ?>"
                        data-admin="<?php echo $esCuentaAdmin ? '1' : '0'; ?>"
                        title="<?php echo $stock > 0 ? 'Agregar al carrito' : 'Agotado'; ?>"
                        aria-label="Agregar <?php echo $nombre; ?> al carrito"
                        <?php echo $stock > 0 ? '' : 'disabled'; ?>>
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1.5 1.5 0 0 0 1.5 1.1h8.6a1.5 1.5 0 0 0 1.5-1.1L21 7H6.2"/><path d="M13 9v5M10.5 11.5h5"/></svg>
                </button>
              </div>
              <div class="tarjeta-back-body">
                <h3 class="tarjeta-nombre"><?php echo $nombre; ?></h3>
                <ul class="tarjeta-specs">
                  <?php foreach (array_slice($specs, 0, 3) as $spec): ?>
                  <li><?php echo htmlspecialchars($spec); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
              <div class="tarjeta-back-footer">
                <?php if ($precioOferta): ?>
                <span class="tarjeta-precio"><?php echo $precioOferta; ?></span>
                <?php else: ?>
                <span class="tarjeta-precio"><?php echo $precio; ?></span>
                <?php endif; ?>
                <span class="tarjeta-envio"><?php echo $envioTexto; ?></span>
              </div>
            </div>
          </div>
        </div>
        <?php
            endforeach;
        else:
        ?>
        <!-- Tarjetas placeholder (sin productos vinculados) -->
        <div class="tarjeta">
          <div class="tarjeta-inner">
            <div class="tarjeta-frontal">
              <h3 class="tarjeta-titulo">Producto Destacado</h3>
              <div class="tarjeta-imagen">
                <img src="../images/audifonosInalambricos.png" alt="Producto">
              </div>
              <div class="tarjeta-footer">Más información ↓</div>
            </div>
            <div class="tarjeta-trasera">
              <div class="tarjeta-back-header"><span class="tarjeta-categoria">Tienda</span></div>
              <div class="tarjeta-back-body">
                <h3 class="tarjeta-nombre">Próximamente</h3>
                <ul class="tarjeta-specs"><li>Productos destacados de la tienda aparecerán aquí</li></ul>
              </div>
              <div class="tarjeta-back-footer">
                <span class="tarjeta-precio">---</span>
                <span class="tarjeta-envio">Visita la tienda</span>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </section>

    <!-- Sección de reportes -->
    <section class="reportes">

      <div class="balanceGeneral">

        <!-- No clickeable — Puntos por compras aprobadas en la tienda (propias y de referidos nivel 1 y 2) -->
        <div class="stat-card stat-card--puntos" title="Ganas puntos por cada compra tuya o de tus referidos (nivel 1 y 2), según el valor de la compra. Cada <?php echo $bonos['puntos_por_bono']; ?> puntos obtienes un bono.">
          <h3>⭐ Mis Puntos</h3>
          <p class="stat-value"><?php echo $puntosAcumulados; ?></p>
          <p class="stat-equivalente">
            <?php
            // Saldo negativo: puntos de una compra cancelada que ya se habian usado en un bono
            if ($puntosAcumulados < 0) {
              echo 'Debes recuperar ' . (-$puntosAcumulados) . ' ' . ($puntosAcumulados === -1 ? 'punto' : 'puntos') . ' de una compra cancelada';
            } else {
              echo $valorPunto > 0
                ? '≈ $ ' . number_format($puntosAcumulados * $valorPunto, 0, ',', '.') . ' COP'
                : 'Valor por definir';
            } ?>
          </p>
          <!-- Bonos: uno cada puntos_por_bono puntos (configurable en el admin); se redime en una compra de la tienda y los puntos vuelven a acumular (bonos-helpers.php) -->
          <div class="puntos-bono">
            <?php if ($bonos['bonos_disponibles'] > 0): ?>
              <p class="bono-disponible">🎁 ¡Tienes <?php echo $bonos['bonos_disponibles'] === 1 ? 'un bono disponible' : $bonos['bonos_disponibles'] . ' bonos disponibles'; ?>!</p>
              <p class="bono-uso">
                <?php echo $bonos['valor_bono'] > 0 ? 'Vale $ ' . number_format($bonos['valor_bono'], 0, ',', '.') . '. ' : ''; ?>Úsalo en tu próxima compra en la
                <a href="../../05-tienda/index.html">tienda</a>.
              </p>
            <?php endif; ?>
            <div class="bono-barra" role="progressbar" aria-label="Avance al siguiente bono"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $bonos['porcentaje_siguiente']; ?>">
              <span style="width: <?php echo $bonos['porcentaje_siguiente']; ?>%"></span>
            </div>
            <p class="bono-faltan">
              Te <?php echo $bonos['puntos_faltantes'] === 1 ? 'falta 1 punto' : 'faltan ' . $bonos['puntos_faltantes'] . ' puntos'; ?>
              para tu <?php echo ($bonos['bonos_disponibles'] + $bonos['bonos_entregados']) > 0 ? 'siguiente bono' : 'primer bono'; ?>
            </p>
            <?php if ($bonos['bonos_entregados'] > 0): ?>
              <p class="bono-entregados">
                ✅ <?php echo $bonos['bonos_entregados'] === 1 ? '1 bono usado' : $bonos['bonos_entregados'] . ' bonos usados'; ?><?php
                echo $bonos['ultimo_pedido'] !== '' ? ' (último en el pedido ' . htmlspecialchars($bonos['ultimo_pedido']) . ')' : ''; ?>
              </p>
            <?php endif; ?>
          </div>
        </div>

        <!-- No clickeable — Total del sistema -->
        <div class="stat-card">
          <h3>📊 Registros del Sistema</h3>
          <p class="stat-value"><?php echo $totalRegistros; ?></p>
        </div>

        <!-- No clickeable — Total del usuario (suma Nivel 1+2+3, calculada por JS) -->
        <div class="stat-card stat-card--usuario" id="card-total-usuario">
          <h3>👤 Total Registros Usuario</h3>
          <p class="stat-value"><span id="total-usuario-valor">0</span></p>
        </div>

        <!-- Clickeables — Cards por nivel -->
        <div class="stat-card stat-card--clickable stat-card--activa" data-nivel="1">
          <h3>1️⃣ Registros Nivel 1</h3>
          <p class="stat-value"><?php echo $totalUsuario; ?></p>
        </div>

        <div class="stat-card stat-card--clickable" data-nivel="2">
          <h3>2️⃣ Registros Nivel 2</h3>
          <p class="stat-value"><?php echo $totalReferidosDirectos; ?></p>
        </div>

        <div class="stat-card stat-card--clickable" data-nivel="3">
          <h3>3️⃣ Registros Nivel 3</h3>
          <p class="stat-value"><?php echo $totalReferidosNivel3; ?></p>
        </div>

      </div>

      <!-- Tabla de registros -->
      <div class="tablaRegistros">
        <h3 class="tabla-titulo">
          <span id="tituloNivel">📋 Listado de Registros — Nivel 1</span>
        </h3>
        <div class="tabla-wrapper">
          <table class="tabla-reporte" id="tablaReporte">
            <thead>
              <tr>
                <th>ASOCIADO</th>
                <th>DOCUMENTO</th>
                <th>EMAIL</th>
                <th>TELÉFONO</th>
                <th>FECHA</th>
              </tr>
            </thead>
            <tbody id="tablaBody">
              <?php if (empty($registrosIniciales)): ?>
                <tr>
                  <td colspan="5" class="tabla-vacia">No hay registros para mostrar.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($registrosIniciales as $reg): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($reg['nombres'] . ' ' . $reg['apellidos']); ?></td>
                    <td><?php echo htmlspecialchars($reg['documento']); ?></td>
                    <td><?php echo htmlspecialchars($reg['email']); ?></td>
                    <td><?php echo htmlspecialchars($reg['telefono']); ?></td>
                    <td><?php echo formatearFecha($reg['fecha']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr class="tabla-total">
                <td colspan="4">Total de registros</td>
                <td id="tablaTotalCount"><?php echo count($registrosIniciales); ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

    </section>

  </div>

  <script src='../js/contenido.js' defer></script>
</body>

</html>